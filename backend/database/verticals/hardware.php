<?php

declare(strict_types=1);

/*
| BR-P09 hardware (الخردوات) template (D33): brand, material, size as filterable selects.
*/

return [
    [
        'key' => 'brand',
        'label' => 'الماركة',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 1,
        'sort_order' => 1,
        'options' => [],
    ],
    [
        'key' => 'material',
        'label' => 'الخامة',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 2,
        'sort_order' => 2,
        'options' => [],
    ],
    [
        'key' => 'size',
        'label' => 'المقاس',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 3,
        'sort_order' => 3,
        'options' => [],
    ],
];
