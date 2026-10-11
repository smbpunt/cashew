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
                'placeholder' => 'statement.upload.placeholder',
                'accept' => '.csv,text/csv',
            ],
            'constraints' => [
                new Assert\Count(min: 1, max: 10, minMessage: 'statement.upload.min_files', maxMessage: 'statement.upload.max_files'),
                new Assert\All([
                    // Banks export CSV as text/csv or text/plain, depending on their encoding
                    new Assert\File(maxSize: '5M', extensions: ['csv' => ['text/csv', 'text/plain', 'application/csv']], extensionsMessage: 'statement.upload.not_csv'),
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
