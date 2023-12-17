<?php
header("Content-Type: application/json");

// получение входящей строки
$string = $_GET['string'];
if (!$string) {
    echo 'Нет входящей строки';
    return false;
}

// Подключение к базе данных
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lmart";

$conn = new mysqli($servername, $username, $password, $dbname);

// Проверка соединения
if ($conn->connect_error) {
    die("Ошибка соединения с БД: " . $conn->connect_error);
}

// фильтруем строку от мусора и оставляем только маленькие буквы
$filteredString = preg_replace('/[^a-z]/', '', strtolower($string));
// получили очищенный массив с кодами из запроса
$characters = str_split($filteredString);


// собираем из базы все возможные значения кода
$sql_codes = 'SELECT DISTINCT code FROM ingredient_type';
$result_codes = $conn->query($sql_codes);
$codesFromDb = array();

if ($result_codes->num_rows > 0) {
    // Вывод результатов
    while ($row = $result_codes->fetch_assoc()) {
        $codesFromDb[] = $row['code'];
    }
} else {
    echo "В базе нет данных";
}


// сравниваем входящую строку с имеющимися в базе кодами и получаем финальный запрос, в котором не может быть ошибки
$finalCodes = array_intersect($characters, $codesFromDb);
//print_r($finalCodes);

/** получаем выборку уникальных значений из запроса, чтобы составить список продуктов в массиве вида код - количество в маске
 * array(2)
 * {
 * ["d"]=> int(1)
 * ["c"]=> int(2)
 * }
 **/

$uniqueFromRequest = array_count_values($finalCodes);


$bigData = array();

foreach ($uniqueFromRequest as $key => $value) {
// Выборка уникальных значений по маске
    $sql = "SELECT it.title as type, i.title as value, i.price, it.code as code FROM ingredient i
left join ingredient_type it on it.id = i.type_id
 WHERE it.code LIKE '{$key}'";

    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $bigData[$key]['count'] = $result->num_rows;

        while ($row = $result->fetch_assoc()) {
            $bigData[$key]['products'][] = $row;
        }
        $bigData[$key]['type'] = $row['type'];
        $bigData[$key]['code'] = $row['code'];

    } else {
        echo "0 результатов ";
    }

}

/**
 * ["products"]=>
 *       array(4) {
["type"]=>
string(10) "Тесто"
["value"]=>
string(23) "Тонкое тесто"
["price"]=>
string(6) "100.00"
["code"]=>
string(1) "d"
}
 */

// дропаем если в маске больше значений чем имеется в базе
foreach ($uniqueFromRequest as $key => $value) {
    if ($bigData[$key]['count'] < $value) {
        echo "В базе недостаточно начинки типа '{$bigData[$key]['type']}' под ваш запрос";
        return false;
    }
}

// а теперь колдунство

// делаем выборку уникальных сборок по каждому типу продукта
// передаём массив значений и их количество(из входящей строки) для построения маски
function uniqueCombination($in, $length = 0)
{
    // количество продуктов на входе
    $count = count($in);
    // возводим в степень, чтобы получить количество вариантов
    $variants = pow(2, $count);

    $return = array();

    for ($i = 0; $i < $variants; $i++) {

        // маска для построения
        $b = sprintf("%0" . $count . "b", $i);

        $out = array();

        for ($j = 0; $j < $count; $j++) {
            // если есть совпадения по маске записываем вариант (отсеиваем если у нас получился вариант с не правильным количеством)
            if ($b{$j} == '1') {
                $out[] = $in[$j];
            }
        }
        count($out) == $length and $return[] = $out;
    }
    return $return;
}

$preFinalArray = array();

foreach ($uniqueFromRequest as $key => $value) {
    $preFinalArray[] = uniqueCombination($bigData[$key]['products'], $value);
}

// рекурсивное склеивание массивов для получения всех итоговых вариантов с уникальными вариантами продуктов по типу
function recursiveMerge($input) {
    $result = array();

    while (list($key, $values) = each($input)) {
// если пусто
        if (empty($values)) {
            continue;
        }

// заполняем первый проход
        if (empty($result)) {
            foreach($values as $value) {
                $result[] = array($key => $value);
            }
        }
        else {
// второй и следующий проходы перебираем данные и склеиваем массивы
            $append = array();

            foreach($result as &$product) {
                // $product указываем в фориче с ссылкой, чтобы сохранить данные при шифте
                // и в первом массиве у нас была полная выборка
// дёргаем значение и уменьшаем их количество, чтобы перебирать их форичем
                $product[$key] = array_shift($values);

                // делаем копию массива
                $copy = $product;

// пока есть значения формируем массив продукта
                foreach($values as $item) {
                    $copy[$key] = $item;
                    $append[] = $copy;
                }

// закидываем полный цикл прохода в один продукт
                array_unshift($values, $product[$key]);
            }

// закидываем в финал проход
            $result = array_merge($result, $append);
        }
    }

    return $result;
}

$finalProducts = recursiveMerge($preFinalArray);

$result = array();

foreach ($finalProducts as $item ){

    $price = 0;
    $products = array();

    foreach ($item as $v){
        for( $i = 0; $i < count($v); $i++){
            $products[] = [
                'type' => $v[$i]['type'],
                'value' => $v[$i]['value']
            ];
            $price = $price + intval($v[0]['price']);
        }
    }

    $result[] = [
        'products' =>  $products,
        'price' => $price
    ];
}

// Преобразование в JSON-массив
$jsonArray = json_encode($result, JSON_UNESCAPED_UNICODE);

// Вывод результата
echo $jsonArray;


$conn->close();

?>