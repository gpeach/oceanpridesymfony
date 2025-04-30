<?php

namespace App\Form;

use App\Entity\GalleryImage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\File;

class GalleryImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
//        $builder
//            ->add('file', FileType::class, [
//                'mapped' => false,
//                'required' => true,
//                'constraints' => [
//                    new File([
//                        'maxSize' => '500M',
//                        'mimeTypes' => [
//                            'image/jpeg',
//                            'image/png',
//                            'video/mp4',
//                            'video/quicktime',
//                        ],
//                        'mimeTypesMessage' => 'Only JPG, PNG, MP4, and MOV are allowed.',
//                    ]),
//                ],
//            ])
//            ->add('name', TextType::class);
        $builder
            ->add('name', TextType::class)
            ->add('file', FileType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'Upload image / video',
                'constraints' => [
                    new File([
                        'maxSize' => '500M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'video/mp4',
                            'video/quicktime',
                        ],
                        'mimeTypesMessage' => 'Only JPG, PNG, MP4, and MOV are allowed.',
                    ]),
                ]
            ])
            ->add('videoUrl', UrlType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'YouTube or Vimeo URL'
            ])
            ->add('description', TextareaType::class, [
                'required' => false
            ]);
    }


    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GalleryImage::class,
        ]);
    }
}
