<?php

namespace App\Form;

use App\Category\CategoryChoices;
use App\Entity\Category;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A category's name, its icon, and the top category it's in, if any.
 *
 * @extends AbstractType<Category>
 */
final class CategoryType extends AbstractType
{
    public function __construct(
        private readonly CategoryChoices $categories,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $owner */
        $owner = $options['owner'];
        $category = $builder->getData();

        $builder
            ->add('name', TextType::class, [
                'label' => 'category.form.name',
                'attr' => ['maxlength' => 100, 'autocomplete' => 'off'],
            ])
            // Picked through the IconPicker component
            ->add('icon', HiddenType::class, [
                'required' => false,
                'error_bubbling' => false,
            ])
            ->add('parent', EntityType::class, [
                'label' => 'category.form.parent',
                'class' => Category::class,
                // Top categories only, but itself: two levels at most
                'choices' => array_values(array_filter($this->categories->tree($owner), static fn (Category $parent): bool => $parent !== $category)),
                'choice_label' => 'name',
                'choice_translation_domain' => false,
                'required' => false,
                'placeholder' => 'category.form.no_parent',
                // A category with subcategories stays at the top
                'disabled' => $category instanceof Category && [] !== $category->getChildren(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('owner')
            ->setAllowedTypes('owner', User::class)
            ->setDefaults([
                'data_class' => Category::class,
                'empty_data' => static fn (Options $options): \Closure => static fn (FormInterface $form): Category => new Category($options['owner']),
            ]);
    }
}
