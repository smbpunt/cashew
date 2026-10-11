<?php

namespace App\ValueResolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The month a page asks for in its query, as "2026-09": its first day; null when it asks for none.
 *
 * For an argument of the same name: #[ValueResolver('month')] ?\DateTimeImmutable $month = null.
 */
#[AsTargetedValueResolver('month')]
final readonly class MonthValueResolver implements ValueResolverInterface
{
    /**
     * @return iterable<?\DateTimeImmutable>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $month = $request->query->all()[$argument->getName()] ?? null;
        if (null === $month) {
            return [null];
        }

        if (!\is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new NotFoundHttpException(\sprintf('No month "%s": one is asked for as "2026-09".', \is_string($month) ? $month : get_debug_type($month)));
        }

        return [new \DateTimeImmutable($month.'-01')];
    }
}
