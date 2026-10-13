<?php

namespace App\Controller;

use App\Calendar\CalendarBuilder;
use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Enum\CalendarFilter;
use App\Repository\TransactionRepository;
use App\Security\OwnerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/calendar')]
final class CalendarController extends AbstractController
{
    /**
     * A month of spending day by day, the month in progress unless another is asked for: what's recurring by default,
     * every debit known on demand.
     */
    #[Route('', name: 'app_calendar', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        CalendarBuilder $calendars,
        #[ValueResolver('month')] ?\DateTimeImmutable $month = null,
        #[MapQueryParameter] ?CalendarFilter $show = null,
    ): Response {
        try {
            $calendar = $calendars->build($user, $month, $show ?? CalendarFilter::Recurring);
        } catch (\OutOfRangeException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        return $this->render('calendar/index.html.twig', [
            'calendar' => $calendar,
        ]);
    }

    /**
     * The series is no longer expected.
     */
    #[Route('/series/{id}/end', name: 'app_calendar_series_end', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'series')]
    #[IsCsrfTokenValid(new Expression('"series-" ~ args["series"].getId()'))]
    public function end(RecurringSeries $series, Request $request, ClockInterface $clock, EntityManagerInterface $entityManager): Response
    {
        $series->end($clock->now()->setTime(0, 0));
        $entityManager->flush();

        return $this->backToCalendar($request);
    }

    /**
     * Though an occurrence of it went missing, the series goes on: expected again, until another one does.
     */
    #[Route('/series/{id}/keep', name: 'app_calendar_series_keep', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'series')]
    #[IsCsrfTokenValid(new Expression('"series-" ~ args["series"].getId()'))]
    public function keep(RecurringSeries $series, Request $request, TransactionRepository $transactions, EntityManagerInterface $entityManager): Response
    {
        $series->keepActive($transactions->findLastDates($series->getOwner())[$series->getAccount()->getId()] ?? $series->getLastSeenOn());
        $entityManager->flush();

        return $this->backToCalendar($request);
    }

    /**
     * The series the user said ended is expected again.
     */
    #[Route('/series/{id}/resume', name: 'app_calendar_series_resume', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'series')]
    #[IsCsrfTokenValid(new Expression('"series-" ~ args["series"].getId()'))]
    public function resume(RecurringSeries $series, Request $request, EntityManagerInterface $entityManager): Response
    {
        $series->resume();
        $entityManager->flush();

        return $this->backToCalendar($request);
    }

    /**
     * To the month, and the filter, the action was made from.
     */
    private function backToCalendar(Request $request): Response
    {
        return $this->redirectToRoute('app_calendar', array_filter([
            'month' => $request->query->getString('month'),
            'show' => $request->query->getString('show'),
        ]), Response::HTTP_SEE_OTHER);
    }
}
