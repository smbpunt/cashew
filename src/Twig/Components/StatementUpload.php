<?php

namespace App\Twig\Components;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Enum\ImportStatus;
use App\Form\StatementUploadType;
use App\Repository\StatementImportRepository;
use App\Repository\TransactionRepository;
use App\Statement\Exception\DuplicateStatementException;
use App\Statement\Import\CutoffPolicy;
use App\Statement\ImportDestination;
use App\Statement\StatementImportNotifier;
use App\Statement\StatementUploader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Uploads statements, then follows their imports until they're over: the Messenger worker announces each status
 * change through Mercure, and the component also polls in case that update is missed. Once they're all done, it says
 * so, and leaves: for the review of the recurring series they brought up, if any, for the dashboard otherwise.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class StatementUpload
{
    use DefaultActionTrait;

    /**
     * The imports followed: those in progress when the page was opened, then those uploaded; most recent first.
     *
     * @var list<int>
     */
    #[LiveProp]
    public array $importIds = [];

    /**
     * Whether the imports followed are all done: the review of the recurring series they brought up shows next, or
     * the dashboard.
     */
    #[LiveProp]
    public bool $completed = false;

    /**
     * Counts uploads, for each of them to start over with an empty dropzone.
     */
    #[LiveProp]
    public int $uploads = 0;

    /**
     * Why some files of the last upload were refused.
     *
     * @var list<string>
     */
    public array $uploadErrors = [];

    private ?FormView $form = null;

    private ?string $nextUrl = null;

    /**
     * @var list<StatementImport>|null
     */
    private ?array $imports = null;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly StatementUploader $uploader,
        private readonly StatementImportRepository $importRepository,
        private readonly TransactionRepository $transactions,
        private readonly ImportDestination $destination,
        private readonly CutoffPolicy $cutoff,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function mount(): void
    {
        // Mounted before IsGranted is checked, which refuses the component to an anonymous user anyway
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Back on the page: the imports still in progress, whichever page they were uploaded from
        $this->importIds = array_reverse(array_map(
            static fn (StatementImport $import): int => (int) $import->getId(),
            $this->importRepository->findInProgress($user),
        ));
    }

    #[LiveAction]
    public function upload(Request $request): void
    {
        $form = $this->createForm();
        $form->submit($request->files->all($form->getName()));

        if (!$form->isValid()) {
            $this->form = $form->createView();

            return;
        }

        /** @var list<UploadedFile> $files */
        $files = $form->get('statements')->getData();
        foreach ($files as $file) {
            try {
                array_unshift($this->importIds, (int) $this->uploader->upload($this->user(), $file)->getId());
            } catch (DuplicateStatementException $exception) {
                $this->uploadErrors[] = $exception->trans($this->translator);
            }
        }

        ++$this->uploads;
        $this->imports = null;
    }

    /**
     * Called when an import's status changes, and while polling.
     */
    #[LiveAction]
    public function refresh(): void
    {
        $imports = $this->getImports();

        if (!$this->completed && [] !== $imports && array_all($imports, static fn (StatementImport $import): bool => ImportStatus::Done === $import->getStatus())) {
            $this->completed = true;

            $session = $this->requestStack->getSession();
            \assert($session instanceof FlashBagAwareSessionInterface);
            $session->getFlashBag()->add('success', new TranslatableMessage('statement.import.completed.flash', [
                'count' => array_sum(array_map(static fn (StatementImport $import): int => $import->getImportedCount(), $imports)),
            ]));
        }
    }

    /**
     * Where to go once the imports are done.
     */
    public function getNextUrl(): string
    {
        return $this->nextUrl ??= $this->destination->url($this->user(), $this->importIds);
    }

    public function getForm(): FormView
    {
        return $this->form ??= $this->createForm()->createView();
    }

    /**
     * @return list<StatementImport>
     */
    public function getImports(): array
    {
        return $this->imports ??= $this->importRepository->findOwned($this->user(), $this->importIds);
    }

    public function isProcessing(): bool
    {
        return array_any($this->getImports(), static fn (StatementImport $import): bool => !$import->getStatus()->isFinished());
    }

    /**
     * The Mercure topics of the imports in progress.
     *
     * @return list<string>
     */
    public function getTopics(): array
    {
        $topics = [];
        foreach ($this->getImports() as $import) {
            if (!$import->getStatus()->isFinished()) {
                $topics[] = StatementImportNotifier::topic($import);
            }
        }

        return $topics;
    }

    /**
     * Whether the user has yet to import anything: time to upload the whole history.
     */
    public function isFirstImport(): bool
    {
        return [] === $this->importIds && !$this->transactions->hasAny($this->user());
    }

    public function getCutoffDate(): \DateTimeImmutable
    {
        return $this->cutoff->cutoffDate();
    }

    /**
     * @return FormInterface<array{statements: list<UploadedFile>}>
     */
    private function createForm(): FormInterface
    {
        return $this->formFactory->create(StatementUploadType::class);
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
