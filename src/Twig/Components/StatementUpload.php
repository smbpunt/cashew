<?php

namespace App\Twig\Components;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Form\StatementUploadType;
use App\Repository\StatementImportRepository;
use App\Repository\TransactionRepository;
use App\Statement\Exception\DuplicateStatementException;
use App\Statement\Import\CutoffPolicy;
use App\Statement\StatementImportNotifier;
use App\Statement\StatementUploader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Uploads statements, then follows their imports until they're over: the Messenger worker announces each status
 * change through Mercure, and the component also polls in case that update is missed.
 *
 * Emits "statement:imported" with the import's id when an import is done, for the rest of the page to refresh.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class StatementUpload
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * The imports uploaded through this component, most recent first.
     *
     * @var list<int>
     */
    #[LiveProp]
    public array $importIds = [];

    /**
     * The imports already announced as done.
     *
     * @var list<int>
     */
    #[LiveProp]
    public array $announcedIds = [];

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

    /**
     * @var list<StatementImport>|null
     */
    private ?array $imports = null;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly StatementUploader $uploader,
        private readonly StatementImportRepository $importRepository,
        private readonly TransactionRepository $transactions,
        private readonly CutoffPolicy $cutoff,
        private readonly Security $security,
    ) {
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
                $this->uploadErrors[] = $exception->getMessage();
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
        foreach ($this->getImports() as $import) {
            $id = (int) $import->getId();
            if ($import->getStatus()->isFinished() && !\in_array($id, $this->announcedIds, true)) {
                $this->announcedIds[] = $id;
                $this->emit('statement:imported', ['id' => $id]);
            }
        }
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
        foreach ($this->getImports() as $import) {
            if (!$import->getStatus()->isFinished()) {
                return true;
            }
        }

        return false;
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
