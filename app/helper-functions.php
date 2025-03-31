<?php

function dd(...$vars): never {
    if (php_sapi_name() === 'cli') {
        // Console output
        foreach ($vars as $var) {
            echo "\e[1;32m" . str_repeat("=", 50) . "\e[0m\n"; // Green divider
            var_dump($var);
            echo "\e[1;32m" . str_repeat("=", 50) . "\e[0m\n\n"; // Green divider
        }
    } else {
        // Web output
        echo '<pre style="background: #333; color: #0f0; padding: 10px; border-radius: 5px;">';
        foreach ($vars as $var) {
            ob_start();
            var_dump($var);
            echo htmlspecialchars(ob_get_clean());
            echo "<hr style='border-color: #0f0;'>";
        }
        echo '</pre>';
    }
    die();
}
