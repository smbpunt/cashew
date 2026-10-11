<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\UX\Dropzone\Form\DropzoneType;

/**
 * @extends AbstractType<array{statements: list<UploadedFile>}>
 */
final class StatementUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('statements', DropzoneType::class, [
            'label' => false,
            'multiple' => true,
            'attr' => [
                'placeholder' => 'Drop your CSV exports here, or click to pick them',
                'accept' => '.csv,text/csv',
            ],
            'constraints' => [
                new Assert\Count(min: 1, max: 10, minMessage: 'Pick at least one file.', maxMessage: 'Upload at most {{ limit }} files at once.'),
                new Assert\All([
                    // Banks export CSV as text/csv or text/plain, depending on their encoding
                    new Assert\File(maxSize: '5M', extensions: ['csv' => ['text/csv', 'text/plain', 'application/csv']], extensionsMessage: 'Only CSV exports are supported.'),
                ]),
            ],
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
