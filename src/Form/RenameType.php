<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The name the user gives what transactions go by: blank to take it back.
 *
 * @extends AbstractType<array{name: ?string}>
 */
final class RenameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'transaction.rename.name',
            'required' => false,
            'attr' => ['maxlength' => 255, 'autocomplete' => 'off'],
            'constraints' => [new Assert\Length(max: 255)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Submitted through a live action, which UX Live Component protects already
            'csrf_protection' => false,
        ]);
    }
}
