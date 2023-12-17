<?php
header("Content-Type: application/json");

// Экземпляр приложения
$app = new App();

// Запуск хэндлера
$result = $app->handle();

print $result;
die();


final class App
{
    public function handle()
    {

        try {
            //  получаем строку и проверяем её
            $input_string = $this->getInputString();
            // дополнительная проверка строки и выборка из базы значений по маске
            $uniqueFromRequest = $this->uniqueFromRequest($input_string);
            // основная функция, кидаем в неё очищенную строку и выборку
            $data = $this->getData($input_string, $uniqueFromRequest);

            return json_encode($data, JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            return json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    private function getData($input_string, $uniqueFromRequest)
    {
        // Тут логика

        $preFinalArray = array();

        foreach ($input_string as $key => $value) {
            $preFinalArray[] = Utils::uniqueCombination($uniqueFromRequest[$key]['products'], $value);
        }

        $finalProducts = Utils::recursiveMerge($preFinalArray);

        $result = array();

        foreach ($finalProducts as $item) {

            $price = 0;
            $products = array();

            foreach ($item as $v) {
                for ($i = 0; $i < count($v); $i++) {
                    $products[] = [
                        'type' => $v[$i]['type'],
                        'value' => $v[$i]['value']
                    ];
                    $price = $price + intval($v[0]['price']);
                }
            }

            $result[] = [
                'products' => $products,
                'price' => $price
            ];
        }

        return $result;
    }

    private function getInputString()
    {
        // Работа с реквестом: валидация + преобразование. Если строка пустая, то выкидывать исключение
        $string = $_GET['string'];
        if (!$string) {
            throw new Exception('Нет входящей строки');
        }

// фильтруем строку от мусора и оставляем только маленькие буквы
        $filteredString = preg_replace('/[^a-z]/', '', strtolower($string));
// получили очищенный массив с кодами из запроса
        $characters = str_split($filteredString);


// собираем из базы все возможные значения кода
        $db = Database::getInstance();
        $sql_codes = $db->getConnection()->query('SELECT DISTINCT code FROM ingredient_type');

        $codesFromDb = array();

        if ($sql_codes->rowCount() > 0) {
            // Вывод результатов
            foreach ($sql_codes as $code) {
                $codesFromDb[] = $code['code'];
            }
        } else {
            throw new Exception('В базе нет данных');
        }

// сравниваем входящую строку с имеющимися в базе кодами и получаем финальный запрос, в котором не может быть ошибки
        $finalCodes = array_intersect($characters, $codesFromDb);
        $uniqueFromRequest = array_count_values($finalCodes);

        return $uniqueFromRequest;

    }


    private function uniqueFromRequest($uniqueFromRequest)
    {

        $result = array();
        $db = Database::getInstance();

        foreach ($uniqueFromRequest as $key => $value) {
// Выборка уникальных значений по маске

            $res = $db->getConnection()->query("
SELECT it.title as type, i.title as value, i.price, it.code as code FROM ingredient i
left join ingredient_type it on it.id = i.type_id
WHERE it.code LIKE '{$key}'
 ");

            $uniqueValues = $res->fetchAll();

            if (count($uniqueValues) > 0) {
                $result[$key]['count'] = count($uniqueValues);
                foreach ($uniqueValues as $uniqueValue) {
                    $result[$key]['products'][] = $uniqueValue;
                }

                $result[$key]['type'] = $uniqueValues[0]['type'];
                $result[$key]['code'] = $uniqueValues[0]['code'];

            } else {
                throw new Exception('0 результатов');
            }

        }


// дропаем если в маске больше значений чем имеется в базе
        foreach ($uniqueFromRequest as $key => $value) {
            if ($result[$key]['count'] < $value) {
                throw new Exception("В базе недостаточно начинки типа '{$result[$key]['type']}' под ваш запрос");
            }
        }

        return $result;

    }

}

class Database
{
    private static $instance;
    private $connection;

    private function __construct()
    {
        // Инициализация подключения к базе данных
        $this->connection = new PDO('mysql:host=localhost;dbname=lmart', 'root', '');
    }


    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}


class Utils
{

// делаем выборку уникальных сборок по каждому типу продукта
// передаём массив значений и их количество(из входящей строки) для построения маски
    static function uniqueCombination($in, $length = 0)
    {
        // количество продуктов на входе
        $count = count($in);
        // возводим в степень, чтобы получить количество вариантов
        $variants = pow(2, $count);

        $result = array();

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
            count($out) == $length and $result[] = $out;
        }
        return $result;
    }


// рекурсивное склеивание массивов для получения всех итоговых вариантов с уникальными вариантами продуктов по типу
    static function recursiveMerge($input)
    {
        $result = array();

        while (list($key, $values) = each($input)) {
// если пусто
            if (empty($values)) {
                continue;
            }

// заполняем первый проход
            if (empty($result)) {
                foreach ($values as $value) {
                    $result[] = array($key => $value);
                }
            } else {
// второй и следующий проходы перебираем данные и склеиваем массивы
                $append = array();

                foreach ($result as &$product) {
                    // $product указываем в фориче с ссылкой, чтобы сохранить данные при шифте
                    // и в первом массиве у нас была полная выборка
// дёргаем значение и уменьшаем их количество, чтобы перебирать их форичем
                    $product[$key] = array_shift($values);

                    // делаем копию массива
                    $copy = $product;

// пока есть значения формируем массив продукта
                    foreach ($values as $item) {
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


}
