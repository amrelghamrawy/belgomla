<?php

declare(strict_types=1);

/*
| BR-P09 school-books template (D17, D18: facet order Grade → Subject → Publisher, then the rest).
| Applied once at tenant creation; the merchant owns and edits the fields afterwards.
*/

return [
    [
        'key' => 'grade',
        'label' => 'السنة الدراسية',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 1,
        'sort_order' => 1,
        'options' => [
            'KG1', 'KG2',
            'أولى ابتدائي', 'ثانية ابتدائي', 'ثالثة ابتدائي', 'رابعة ابتدائي', 'خامسة ابتدائي', 'سادسة ابتدائي',
            'أولى إعدادي', 'ثانية إعدادي', 'ثالثة إعدادي',
            'أولى ثانوي', 'ثانية ثانوي', 'ثالثة ثانوي',
        ],
    ],
    [
        'key' => 'subject',
        'label' => 'المادة',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 2,
        'sort_order' => 2,
        'options' => [
            'عربي', 'إنجليزي', 'رياضيات', 'علوم', 'دراسات', 'دين', 'فرنساوي',
            'فيزياء', 'كيمياء', 'أحياء', 'فلسفة', 'تاريخ', 'جغرافيا', 'حاسب آلي',
        ],
    ],
    [
        'key' => 'publisher',
        'label' => 'الشركة',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 3,
        'sort_order' => 3,
        'options' => ['الأضواء', 'المعلم', 'كراسة الأول', 'سلاح التلميذ', 'الامتحان', 'بت بايت', 'جيم'],
    ],
    [
        'key' => 'term',
        'label' => 'الترم',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 4,
        'sort_order' => 4,
        'options' => ['الترم الأول', 'الترم الثاني', 'كامل العام'],
    ],
    [
        'key' => 'track',
        'label' => 'المسار',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 5,
        'sort_order' => 5,
        'options' => ['عربي', 'لغات'],
    ],
    [
        'key' => 'edition_type',
        'label' => 'نوع النسخة',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 6,
        'sort_order' => 6,
        'options' => ['الكتاب الأساسي', 'المراجعة النهائية', 'كتاب الامتحانات', 'ملحق المراجعة والامتحانات'],
    ],
    [
        'key' => 'academic_year',
        'label' => 'العام الدراسي',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 7,
        'sort_order' => 7,
        'options' => ['2025/2026', '2026/2027'],
    ],
];
