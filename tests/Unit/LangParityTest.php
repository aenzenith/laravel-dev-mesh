<?php

it('defines the same keys in every language', function () {
    $lang = dirname(__DIR__, 2).'/lang';
    $english = array_keys(require "{$lang}/en/dashboard.php");

    foreach (glob("{$lang}/*/dashboard.php") ?: [] as $file) {
        expect(array_keys(require $file))->toBe($english, basename(dirname($file)).' is out of sync with en');
    }
});
