<?php

namespace App\Counterparty;

use App\Entity\Counterparty;
use App\Entity\User;
use App\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Finds the counterparty a label tells, or creates it.
 *
 * Reset between the messages a worker handles, for its cache not to grow with every import.
 */
final class CounterpartyResolver implements ResetInterface
{
    /**
     * @var array<string, Counterparty>
     */
    private array $counterparties = [];

    public function __construct(
        private readonly CounterpartyNormalizer $normalizer,
        private readonly CounterpartyRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return Counterparty|null null when the label only holds references
     */
    public function resolve(User $owner, string $label): ?Counterparty
    {
        $key = $this->normalizer->normalize($label);
        if ('' === $key) {
            return null;
        }

        $cacheKey = $owner->getId()."\0".$key;
        if (!isset($this->counterparties[$cacheKey]) || !$this->entityManager->contains($this->counterparties[$cacheKey])) {
            $this->counterparties[$cacheKey] = $this->repository->findOneBy(['owner' => $owner, 'key' => $key])
                ?? $this->create($owner, $key);
        }

        return $this->counterparties[$cacheKey];
    }

    public function reset(): void
    {
        $this->counterparties = [];
    }

    private function create(User $owner, string $key): Counterparty
    {
        $counterparty = new Counterparty($owner, $key);
        $this->entityManager->persist($counterparty);

        return $counterparty;
    }
}
