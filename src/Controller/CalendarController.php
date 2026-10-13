<?php

namespace App\Controller;

use App\Calendar\CalendarBuilder;
use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Enum\CalendarFilter;
use App\Recurrence\SeriesSchedule;
use App\Repository\TransactionRepository;
use App\Security\OwnerVoter;
use App\Time\Month;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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
        ?Month $month = null,
        #[MapQueryParameter] ?CalendarFilter $show = null,
    ): Response {
        return $this->render('calendar/index.html.twig', [
            'calendar' => $calendars->build($user, $month, $show ?? CalendarFilter::Recurring),
        ]);
    }

    /**
     * The series is no longer expected.
     */
    #[Route('/series/{id}/end', name: 'app_calendar_series_end', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'series')]
    #[IsCsrfTokenValid(new Expression('"series-" ~ args["series"].getId()'))]
    public function end(
        RecurringSeries $series,
        ClockInterface $clock,
        EntityManagerInterface $entityManager,
        ?Month $month = null,
        #[MapQueryParameter] ?CalendarFilter $show = null,
    ): Response {
        $series->end($clock->now()->setTime(0, 0));
        $entityManager->flush();

        return $this->backToCalendar($month, $show);
    }

    /**
     * Though an occurrence of it went missing, the series goes on: expected again, until another one does.
     */
    #[Route('/series/{id}/keep', name: 'app_calendar_series_keep', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'series')]
    #[IsCsrfTokenValid(new Expression('"series-" ~ args["series"].getId()'))]
    public function keep(
        RecurringSeries $series,
        TransactionRepository $transactions,
        SeriesSchedule $schedule,
        EntityManagerInterface $entityManager,
        ?Month $month = null,
        #[MapQueryParameter] ?CalendarFilter $show = null,
    ): Response {
        $series->keepActive($schedule->knownUntil($series, $transactions->findLastDates($series->getOwner())));
        $entityManager->flush();

        return $this->backToCalendar($month, $show);
    }

    /**
     * The series the user said ended is expected again.
     */
    #[Route('/series/{id}/resume', name: 'app_calendar_series_resume', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(OwnerVoter::OWNER, 'series')]
    #[IsCsrfTokenValid(new Expression('"series-" ~ args["series"].getId()'))]
    public function resume(
        RecurringSeries $series,
        EntityManagerInterface $entityManager,
        ?Month $month = null,
        #[MapQueryParameter] ?CalendarFilter $show = null,
    ): Response {
        $series->resume();
        $entityManager->flush();

        return $this->backToCalendar($month, $show);
    }

    /**
     * To the month, and the filter, the action was made from.
     */
    private function backToCalendar(?Month $month, ?CalendarFilter $show): Response
    {
        return $this->redirectToRoute('app_calendar', array_filter([
            'month' => $month?->key(),
            'show' => $show?->value,
        ]), Response::HTTP_SEE_OTHER);
    }
}
