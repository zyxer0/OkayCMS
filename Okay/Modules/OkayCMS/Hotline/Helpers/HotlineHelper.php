<?php


namespace Okay\Modules\OkayCMS\Hotline\Helpers;


use Okay\Core\EntityFactory;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Core\Router;
use Okay\Core\Routes\ProductRoute;
use Okay\Core\Settings;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\RouterCacheEntity;
use Okay\Entities\VariantsEntity;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\OkayCMS\Hotline\Entities\HotlineRelationsEntity;
use Okay\Modules\OkayCMS\Hotline\Init\Init;

class HotlineHelper
{

    /** @var Image */
    private $image;

    /** @var Money */
    private $money;

    /** @var Settings */
    private $settings;

    /** @var QueryFactory */
    private $queryFactory;

    /** @var Languages */
    private $languages;

    /** @var XmlFeedHelper */
    private $feedHelper;
    
    private $UAH_currency;
    private $USD_currency;
    private $mainCurrency;
    private $allCurrencies;
    
    public function __construct(
        Image         $image,
        Money         $money,
        Settings      $settings,
        QueryFactory  $queryFactory,
        Languages     $languages,
        EntityFactory $entityFactory,
        XmlFeedHelper $feedHelper
    ) {
        $this->image        = $image;
        $this->money        = $money;
        $this->settings     = $settings;
        $this->queryFactory = $queryFactory;
        $this->languages    = $languages;
        $this->feedHelper   = $feedHelper;
        
        /** @var CurrenciesEntity $currenciesEntity */
        $currenciesEntity = $entityFactory->get(CurrenciesEntity::class);
        
        $this->mainCurrency = $currenciesEntity->getMainCurrency();
        foreach ($currenciesEntity->find(['enabled' => true]) as $c) {
            $this->allCurrencies[$c->id] = $c;
            if ($c->code === "UAH") {
                $this->UAH_currency = $c;
            } elseif ($c->code === "USD") {
                $this->USD_currency = $c;
            }
        }
    }

    /**
     * Метод возвращает итоговый запрос, который достаёт все товары с изображениями с свойствами.
     * По результатам замеров, лучшие результаты производительности достигаются при джоине ленговых таблиц 
     * после фильтрации.
     * Фильтрация результатов и группировка свойств с изображениями вынесена в подзапрос, 
     * который формируется методом getSubSelect()
     *
     * @param string|integer $feedId
     * @param array $uploadCategories
     * @return Select
     */
    public function getQuery($feedId, $uploadCategories = []) : Select
    {
        $subSelect = $this->getSubSelect($feedId, $uploadCategories);
        if ($this->settings->get('okaycms__hotline__use_full_description_to_hotline')) {
            $descriptionField = 'lp.description';
        } else {
            $descriptionField = 'lp.annotation';
        }
        
        $sql = $this->queryFactory->newSelect();
        $sql->cols([
            't.*',
            'lp.name as product_name',
            'lv.name as variant_name',
            'lb.name as vendor',
            $descriptionField . ' AS description',
        ])->fromSubSelect($subSelect, 't')
            ->leftJoin(ProductsEntity::getLangTable().' AS lp', 'lp.product_id = t.product_id and lp.lang_id=' . $this->languages->getLangId())
            ->leftJoin(VariantsEntity::getLangTable().' AS lv', 'lv.variant_id = t.variant_id and lv.lang_id=' . $this->languages->getLangId())
            ->leftJoin(BrandsEntity::getLangTable().' AS lb', 'lb.brand_id = t.brand_id and lb.lang_id=' . $this->languages->getLangId());
        
        return ExtenderFacade::execute(__METHOD__, $sql, func_get_args());
    }

    /**
     * Метод возвращает подзапрос, который фильтрует и сортирует результаты, здесь достаются только не мультиязычные 
     * данные, кроме свойств. Свойства нужно доставать здесь, т.к. их группируем через GROUP_CONCAT()
     *
     * @param string|integer $feedId
     * @param array $uploadCategories
     * @return Select
     */
    private function getSubSelect($feedId, $uploadCategories = []) : Select
    {
        $sql = $this->queryFactory->newSelect();

        $categoryFilter = '';
        if (!empty($uploadCategories)) {
            $categoryFilter = "OR p.id IN (SELECT product_id FROM __products_categories WHERE category_id IN (:category_id))";
            $sql->bindValue('category_id', (array)$uploadCategories);
        }

        $sql->cols([
            'v.stock',
            'v.price',
            'v.sku',
            'v.currency_id',
            'v.id AS variant_id',
            'p.id AS product_id',
            'p.url',
            'r.slug_url',
            'p.main_category_id',
            'p.brand_id',
        ])  ->from(VariantsEntity::getTable() . ' AS v')
            ->leftJoin(ProductsEntity::getTable().' AS  p', 'v.product_id=p.id')
            ->leftJoin(RouterCacheEntity::getTable().' AS r', 'r.url = p.url AND r.type="product"')
            ->where('p.visible')
            ->where("p.id NOT IN (SELECT entity_id FROM " . HotlineRelationsEntity::getTable() . " WHERE feed_id = :feed_id AND entity_type = 'product' AND include = 0)")
            ->where("(p.id IN (SELECT entity_id FROM " . HotlineRelationsEntity::getTable() . " WHERE feed_id = :feed_id AND entity_type = 'product' AND include = 1) OR
                           p.brand_id IN (SELECT entity_id FROM " . HotlineRelationsEntity::getTable() . " WHERE feed_id = :feed_id AND entity_type = 'brand')
                           {$categoryFilter})")
            ->bindValue('feed_id', $feedId)
            ->groupBy(['v.id'])
            ->orderBy(['p.position DESC']);

        if (!$this->settings->get('okaycms__hotline__upload_without_images')) {
            $sql->where('p.main_image_id != \'\' AND p.main_image_id IS NOT NULL');
        }
        
        if ($this->settings->get('okaycms__hotline__upload_only_available_to_hotline')) {
            $sql->where('(v.stock >0 OR v.stock is NULL)');
        }

        // Чтобы не писать запрос на группировку свойств и изображений, который может стать невалидным, используем
        // feedHelper чтобы он добавил этот запрос
        $sql = $this->feedHelper->joinImages($sql);
        $sql = $this->feedHelper->joinFeatures($sql);
        
        return ExtenderFacade::execute(__METHOD__, $sql, func_get_args());
    }

    /**
     * Формируем описание офера в виде массива
     * 
     * @param object $product строка выборки из базы (запрос формирующийся методом getQuery),
     * но после отработки методов attachFeatures и attachImages.
     * @param bool $addVariantUrl Если true будет добавлен урл на определенный вариант
     * @return array
     * @throws \Exception
     */
    public function getItem($product, $addVariantUrl = false) : array
    {
        $result['id']['data'] = $product->variant_id;
        $result['group_id']['data'] = $product->product_id;
        $result['categoryId']['data'] = $product->main_category_id;
        if (!empty($product->sku)) {
            $result['code']['data'] = $this->feedHelper->escape($product->sku);
        }
        $result['name']['data'] = $this->feedHelper->escape($product->product_name . (!empty($product->variant_name) ? ' ' . $product->variant_name : ''));
        if (!empty($product->vendor)) {
            $result['vendor']['data'] = $this->feedHelper->escape($product->vendor);
        }
        $result['description']['data'] = $this->feedHelper->escape($product->description);
        
        // Указываем связку урла товара и его slug
        ProductRoute::setUrlSlugAlias($product->url, $product->slug_url);
        if ($addVariantUrl) {
            $result['url']['data'] = Router::generateUrl('product', ['url' => $product->url, 'variantId' => $product->variant_id], true);
        } else {
            $result['url']['data'] = Router::generateUrl('product', ['url' => $product->url], true);
        }

        if ($product->stock || $product->stock === null) {
            $result['stock']['data'] = 'В наличии';
        } else {
            $result['stock']['data'] = 'Под заказ';
        }

        if (isset($this->allCurrencies[$product->currency_id])) {

            // Переводим в основную валюту сайта
            $variantCurrency = $this->allCurrencies[$product->currency_id];
            if (!empty($product->currency_id) && $variantCurrency->rate_from != $variantCurrency->rate_to) {
                $product->price = round($product->price * $variantCurrency->rate_to / $variantCurrency->rate_from, 2);
            }

            // Приводим цены в гривнах
            if ($this->UAH_currency) {
                $result['priceRUAH']['data'] = $this->money->convert($product->price, $this->UAH_currency->id);
            } else {
                $result['priceRUAH']['data'] = $this->money->convert($product->price, $this->mainCurrency->id);
            }

            // Приводим цены в долларах
            if ($this->USD_currency) {
                $result['priceRUSD']['data'] = $this->money->convert($product->price, $this->USD_currency->id);
            }
        }

        if (!empty($product->images)) {
            foreach ($product->images as $imageFilename) {
                $i['tag'] = 'image';
                $i['data'] = $this->image->getResizeModifier($imageFilename, 800, 600);
                $result[] = $i;
            }
        }

        $guaranteeId = $this->settings->get('okaycms__hotline__guarantee_manufacturer');
        $guaranteeShopId = $this->settings->get('okaycms__hotline__guarantee_shop');
        $countryOfOriginParamId = $this->settings->get('okaycms__hotline__country_of_origin');
        
        if (isset($product->features[$guaranteeId])) {
            $result[] = [
                'data' => $this->feedHelper->escape($product->features[$guaranteeId]['values_string']),
                'tag' => 'guarantee',
                'attributes' => [
                    'type' => 'manufacturer',
                ],
            ];
            unset($product->features[$guaranteeId]);
        }

        if (isset($product->features[$guaranteeShopId])) {
            $result[] = [
                'data' => $this->feedHelper->escape($product->features[$guaranteeShopId]['values_string']),
                'tag' => 'guarantee',
                'attributes' => [
                    'type' => 'shop',
                ],
            ];
            unset($product->features[$guaranteeShopId]);
        }

        if (isset($product->features[$countryOfOriginParamId])) {
            $result[] = [
                'data' => $this->feedHelper->escape($product->features[$countryOfOriginParamId]['values_string']),
                'tag' => 'param',
                'attributes' => [
                    'name' => 'Країна виготовлення',
                ],
            ];
            unset($product->features[$countryOfOriginParamId]);
        }
        
        if (!empty($product->features)) {
            foreach ($product->features as $feature) {
                foreach ($feature['values'] as $value) {
                    $result[] = [
                        'data' => $this->feedHelper->escape($value),
                        'tag' => 'param',
                        'attributes' => [
                            'name' => $this->feedHelper->escape($feature['name']),
                        ],
                    ];
                }
            }
        }

        return ExtenderFacade::execute(__METHOD__, $result, func_get_args());
    }
}