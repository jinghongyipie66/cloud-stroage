<?php
/** Sorting is applied to the full authorized result set BEFORE pagination. */
function file_list_sort_options() {
    return [
        'default' => '默认排序',
        'mtime_desc' => '修改时间：新到旧',
        'mtime_asc' => '修改时间：旧到新',
        'size_desc' => '文件大小：大到小',
        'size_asc' => '文件大小：小到大',
    ];
}

function file_list_normalize_sort($value) {
    return is_string($value) && array_key_exists($value, file_list_sort_options()) ? $value : 'default';
}

function file_list_sort_records($records, $order) {
    $order = file_list_normalize_sort($order);
    if ($order === 'default') return $records;
    $field = strpos($order, 'size_') === 0 ? 'size' : 'mtime';
    $descending = substr($order, -4) === 'desc';
    usort($records, function ($a, $b) use ($field, $descending) {
        $comparison = ((int)($a[$field] ?? 0)) <=> ((int)($b[$field] ?? 0));
        if ($comparison !== 0) return $descending ? -$comparison : $comparison;
        // Stable tie-breaker, including same-name search results in different directories.
        return strcmp($a['path'] ?? $a['name'], $b['path'] ?? $b['name']);
    });
    return $records;
}

function file_list_sort_names($names, $directory, $order) {
    $order = file_list_normalize_sort($order);
    if ($order === 'default') return $names;
    $records = [];
    foreach ($names as $name) {
        $stat = @stat(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name);
        $records[] = ['name' => $name, 'size' => $stat['size'] ?? 0, 'mtime' => $stat['mtime'] ?? 0];
    }
    return array_column(file_list_sort_records($records, $order), 'name');
}
