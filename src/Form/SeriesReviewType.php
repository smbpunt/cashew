<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the user calls a recurring series, and the category of its transactions.
 *
 * @extends AbstractType<array{name: string, category: ?Category}>
 */
final class SeriesReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'review.series.name',
                'attr' => ['maxlength' => 255, 'autocomplete' => 'off'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('category', EntityType::class, [
                'label' => 'review.series.category',
                'class' => Category::class,
                'choices' => $options['categories'],
                'choice_label' => 'path',
                'choice_translation_domain' => false,
                'required' => false,
                'placeholder' => 'review.series.no_category',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('categories')
            ->setAllowedTypes('categories', 'array')
            ->setDefaults([
                // Submitted through a live action, which UX Live Component protects already
                'csrf_protection' => false,
            ]);
    }
}
