<?php

namespace App\ValueResolver;

use App\Time\Month;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The month a page asks for in its query, as "2026-09", for a Month argument of the same name; null when it asks for
 * none: ?Month $month = null.
 */
final readonly class MonthValueResolver implements ValueResolverInterface
{
    /**
     * @return iterable<?Month>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Month::class !== $argument->getType()) {
            return [];
        }

        $month = $request->query->all()[$argument->getName()] ?? null;
        if (null === $month) {
            return [null];
        }

        try {
            return [Month::fromKey(\is_string($month) ? $month : get_debug_type($month))];
        } catch (\InvalidArgumentException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }
    }
}
