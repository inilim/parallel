<?php

use Inilim\Tool\VD;

require_once __DIR__ . '/vendor/autoload.php';

// ============================================================
//  ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ (без изменений)
// ============================================================

function decimalToFraction(string $numStr): array
{
    $numStr = trim($numStr);
    if ($numStr === '') throw new \InvalidArgumentException("Пустое число");
    $sign = 1;
    if ($numStr[0] === '-') {
        $sign = -1;
        $numStr = substr($numStr, 1);
    } elseif ($numStr[0] === '+') {
        $numStr = substr($numStr, 1);
    }
    if (!preg_match('/^\d*\.?\d+$/', $numStr)) throw new \InvalidArgumentException("Некорректное число: $numStr");
    if (strpos($numStr, '.') !== false) {
        [$intPart, $fracPart] = explode('.', $numStr);
        $denom = pow(10, strlen($fracPart));
        $num = (int)($intPart . $fracPart);
    } else {
        $num = (int)$numStr;
        $denom = 1;
    }
    $num *= $sign;
    $g = gcd(abs($num), $denom);
    return [$num / $g, $denom / $g];
}

function parseRatio(string $raw): array
{
    $parts = explode(':', $raw);
    if (count($parts) !== 2) throw new \InvalidArgumentException("Формат 'a:b', получено: $raw");
    [$num1, $den1] = decimalToFraction($parts[0]);
    [$num2, $den2] = decimalToFraction($parts[1]);
    $numerator = $num1 * $den2;
    $denominator = $num2 * $den1;
    $g = gcd(abs($numerator), abs($denominator));
    return [$numerator / $g, $denominator / $g];
}

function gcd(int $a, int $b): int
{
    while ($b !== 0) {
        [$a, $b] = [$b, $a % $b];
    }
    return abs($a);
}

// ============================================================
//  НАСТРОЙКИ
// ============================================================

// Курсы (формат "число:число")
$raw_item_chaos   = '1:246';    // покупка item за Chaos (item:chaos)
$raw_divine_item  = '1.20:1';    // продажа item за Divine (divine:item)
$raw_divine_chaos = '1:212';   // обмен Divine → Chaos (divine:chaos)

// Доступные запасы валют (null = без ограничений)
$max_ch = 1900;    // сколько Chaos готовы вложить в стратегию 1
$max_di = 18;      // сколько Divine готовы вложить в стратегию 2

// ============================================================
//  РАЗБОР КУРСОВ
// ============================================================

[$A1, $B1] = parseRatio($raw_item_chaos);     // покупка: отдаём B1 Ch, получаем A1 item
[$A2, $B2] = parseRatio($raw_divine_item);    // продажа: отдаём B2 item, получаем A2 Di
[$A3, $B3] = parseRatio($raw_divine_chaos);   // обмен:   отдаём A3 Di, получаем B3 Ch

// Цены за 1 item (дробные)
$buy_item_ch  = $B1 / $A1;
$sell_item_di = $A2 / $B2;
$di_to_ch     = $B3 / $A3;

// Коэффициенты прибыли на 1 item
$k1 = $sell_item_di * $di_to_ch - $buy_item_ch;   // Ch → item → Di → Ch
$k2 = $sell_item_di - $buy_item_ch / $di_to_ch;   // Di → Ch → item → Di

// ============================================================
//  ВЫВОД БАЗОВОЙ ИНФОРМАЦИИ
// ============================================================

echo "========================================\n";
echo "ВВЕДЁННЫЕ КУРСЫ:\n";
echo "  Покупка item за Ch:   $raw_item_chaos   (отдаём $B1 Ch за $A1 item)\n";
echo "  Продажа item за Di:   $raw_divine_item  (отдаём $B2 item за $A2 Di)\n";
echo "  Обмен Di → Ch:        $raw_divine_chaos (отдаём $A3 Di за $B3 Ch)\n";

echo "\nОГРАНИЧЕНИЯ ПО ВАЛЮТЕ:\n";
echo "  max_ch = " . ($max_ch ?? "∞") . " Chaos\n";
echo "  max_di = " . ($max_di ?? "∞") . " Divine\n";

echo "\nПРИБЫЛЬ НА 1 ITEM (непрерывная модель):\n";
echo "  k1 = " . round($k1, 6) . " Ch\n";
echo "  k2 = " . round($k2, 6) . " Di\n";

if ($k1 <= 0 && $k2 <= 0) {
    echo "\n❌ Арбитраж отсутствует.\n";
    exit;
}

// ============================================================
//  ПОИСК ЦЕЛОЧИСЛЕННЫХ СТРАТЕГИЙ С УЧЁТОМ ЛИМИТОВ
// ============================================================

echo "\n✅ АРБИТРАЖ ВОЗМОЖЕН. Поиск целых операций с учётом лимитов...\n";

// --------------------------
// Стратегия 1: Ch → item → Di → Ch
// --------------------------
if ($k1 > 0) {
    // Базовый минимальный множитель для целых обменов (как раньше)
    $g1 = gcd($A1, $B2);
    $step1 = $B2 / $g1;                     // шаг лотов покупки (k1)
    $m_min = $A3 / gcd($A3, ($A1 / $g1) * $A2); // минимальный множитель m
    $base_k1 = $m_min * $step1;              // минимальное количество лотов покупки для целости

    $base_ch = $base_k1 * $B1;               // стартовые Ch в минимальном цикле

    if ($max_ch !== null && $base_ch > $max_ch) {
        echo "\n▶ Стратегия 1 (Ch → item → Di → Ch) невозможна: минимальный цикл требует $base_ch Ch > лимит $max_ch Ch.\n";
    } else {
        // Находим максимальный множитель, чтобы ch_spent ≤ max_ch
        $mult = $max_ch === null ? $base_k1 : intdiv($max_ch, $base_ch) * $base_k1;
        // $mult должно быть кратно $base_k1, чтобы сохранялась целочисленность
        $mult = $mult - ($mult % $base_k1);
        if ($mult == 0) {
            echo "\n▶ Стратегия 1 невозможна при данном лимите.\n";
        } else {
            $k1_ = $mult;
            $item_bought = $k1_ * $A1;
            $ch_spent    = $k1_ * $B1;
            $di_received = ($item_bought / $B2) * $A2;   // целое
            $ch_final    = ($di_received / $A3) * $B3;   // целое
            $profit      = $ch_final - $ch_spent;

            echo "\n▶ Стратегия 1 (Ch → item → Di → Ch):\n";
            echo "  • Потратить: $ch_spent Ch\n";
            echo "  • Купить: $item_bought item\n";
            echo "  • Продать и получить: $di_received Di\n";
            echo "  • Обменять Di и получить: $ch_final Ch\n";
            echo "  • Прибыль: $profit Ch\n";
            if ($mult < $base_k1 * 2) {
                echo "  (это минимальный целый цикл)\n";
            } else {
                echo "  (цикл увеличен в " . ($mult / $base_k1) . " раз(а) для использования лимита)\n";
            }
        }
    }
}

// --------------------------
// Стратегия 2: Di → Ch → item → Di
// --------------------------
if ($k2 > 0) {
    // Базовый минимальный множитель для целых обменов
    $g2 = gcd($B3, $B1);
    $step_n = $B1 / $g2;
    $p_min = $B2 / gcd($B2, ($B3 / $g2) * $A1);
    $base_n = $p_min * $step_n;             // минимальное n (количество лотов обмена Di→Ch)

    $base_di = $base_n * $A3;               // стартовые Di в минимальном цикле

    if ($max_di !== null && $base_di > $max_di) {
        echo "\n▶ Стратегия 2 (Di → Ch → item → Di) невозможна: минимальный цикл требует $base_di Di > лимит $max_di Di.\n";
    } else {
        $mult = $max_di === null ? $base_n : intdiv($max_di, $base_di) * $base_n;
        $mult = $mult - ($mult % $base_n);
        if ($mult == 0) {
            echo "\n▶ Стратегия 2 невозможна при данном лимите.\n";
        } else {
            $n = $mult;
            $di_spent    = $n * $A3;
            $ch_received = $n * $B3;
            $item_bought = ($ch_received / $B1) * $A1;   // целое
            $di_final    = ($item_bought / $B2) * $A2;   // целое
            $profit      = $di_final - $di_spent;

            echo "\n▶ Стратегия 2 (Di → Ch → item → Di):\n";
            echo "  • Потратить: $di_spent Di\n";
            echo "  • Обменять на: $ch_received Ch\n";
            echo "  • Купить: $item_bought item\n";
            echo "  • Продать и получить: $di_final Di\n";
            echo "  • Прибыль: $profit Di\n";
            if ($mult < $base_n * 2) {
                echo "  (это минимальный целый цикл)\n";
            } else {
                echo "  (цикл увеличен в " . ($mult / $base_n) . " раз(а) для использования лимита)\n";
            }
        }
    }
}

echo "========================================\n";
