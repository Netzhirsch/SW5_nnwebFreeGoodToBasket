<?php

namespace nnwebFreeGoodToBasket;

use Enlight_Event_EventArgs;
use Enlight_Event_Exception;
use Enlight_Exception;
use Enlight_Hook_HookArgs;
use Enlight_View_Default;
use Exception;
use Shopware\Components\Cart\Struct\CartItemStruct;
use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\DBALConfigReader;
use Shopware\Models\Shop\Shop;
use Shopware_Controllers_Frontend_Checkout;
use Zend_Db_Adapter_Exception;

class nnwebFreeGoodToBasket extends Plugin {

    public static function getSubscribedEvents() {
        return [
            'Enlight_Controller_Action_PostDispatch_Frontend_Checkout' => 'onPostDispatch',
            'Shopware_Modules_Basket_DeleteArticle_Start' => 'onDeletedArticle',
            'sBasket::sGetBasket::after' => 'afterGetBasket'
        ];
    }

    public function install(InstallContext $context) {
        $context->scheduleClearCache(InstallContext::CACHE_LIST_DEFAULT);
        parent::install($context);
    }

    /**
     * @param Enlight_Event_EventArgs $args
     * @throws Enlight_Event_Exception
     * @throws Enlight_Exception
     * @throws Zend_Db_Adapter_Exception
     * @throws Exception
     */
    public function onPostDispatch(Enlight_Event_EventArgs $args) {

        /** @var Shopware_Controllers_Frontend_Checkout $controller */
        $controller = $args->get('subject');

        $this->disablePromotionBox($controller);

        $this->addPromotionFreeGoods();

    }

    /**
     * @param Enlight_Hook_HookArgs $args
     */
    public function afterGetBasket(Enlight_Hook_HookArgs $args)
    {
        $config = $this->getConfig();
        if (!$config["nnwebFreeGoodToBasket_deleteIfPromotionIsExpired"])
            return;

        $basket = $args->getReturn();

        $session = Shopware()->Session();
        if (!empty($basket)) {
            $netzhirschFreeGoodToBasketFreedGoodsAdded = $session->get('netzhirschFreeGoodToBasketFreedGoodsAdded');
            foreach ($basket["content"] as $basketKey => $basketItem) {
                if (in_array($basketItem['ordernumber'], $netzhirschFreeGoodToBasketFreedGoodsAdded) && empty($basketItem["isFreeGoodByPromotionId"])) {
                    unset($basket["content"][$basketKey]);
                }
            }
        }

        $args->setReturn($basket);
    }

    /**
     * @param Enlight_Event_EventArgs $args
     * @throws Enlight_Event_Exception
     * @throws Enlight_Exception
     * @throws Zend_Db_Adapter_Exception
     */
    public function onDeletedArticle(Enlight_Event_EventArgs $args)
    {
        $id = $args->get('id');
        if ($id == 'voucher')
            return;

        $basket = Shopware()->Modules()->Basket();
        $basketData = $basket->sGetBasket();

        $orderNumber = null;
        $session = Shopware()->Session();
        if (!empty($basketData)) {
            $freeGoods = $this->getFreedGoods($basketData,$session);
            $content = $basketData['content'];
            if (!empty($content)) {
                foreach ($content as $basketArticle) {
                    if ($basketArticle['id'] == $id) {
                        foreach ($freeGoods as $freeGood) {
                            if ($freeGood['ordernumber'] == $basketArticle['ordernumber'] && !empty($basketArticle['isFreeGoodByPromotionId']))
                                $orderNumber = $basketArticle['ordernumber'];
                        }
                        if (!empty($basketArticle['isFreeGoodByPromotionId']))
                            $orderNumber = $basketArticle['ordernumber'];
                    }
                }
            }
        }
        $netzhirschFreeGoodToBasketFreedGoodsDeleted = $session->get('netzhirschFreeGoodToBasketFreedGoodsDeleted');
        if (!empty($orderNumber) && !in_array($orderNumber,$netzhirschFreeGoodToBasketFreedGoodsDeleted)) {
            $netzhirschFreeGoodToBasketFreedGoodsDeleted[] = $orderNumber;
            Shopware()->Session()->offsetSet('netzhirschFreeGoodToBasketFreedGoodsDeleted',$netzhirschFreeGoodToBasketFreedGoodsDeleted);
        }
    }

    private function disablePromotionBox(Shopware_Controllers_Frontend_Checkout $controller){
        /** @var Enlight_View_Default $view */
        $view = $controller->View();
        $config = $this->getConfig();
        if (isset($config['nnwebFreeGoodToBasket_showPromotionBox']) && !$config['nnwebFreeGoodToBasket_showPromotionBox']) {
            $view->assign('freeGoods',0);
        }
    }

    /**
     * @throws Enlight_Event_Exception
     * @throws Enlight_Exception
     * @throws Zend_Db_Adapter_Exception
     */
    public function addPromotionFreeGoods()
    {

        $basket = Shopware()->Modules()->Basket()->sGetBasket();
        if (empty($basket))
            return;

        $session = Shopware()->Session();
        $freeGoods = $this->getFreedGoods($basket,$session);
        if (empty($freeGoods)) {
           return;
        }

        $netzhirschFreeGoodToBasketFreedGoodsDeleted = $session->get('netzhirschFreeGoodToBasketFreedGoodsDeleted');
        $netzhirschFreeGoodToBasketFreedGoodsAdded = $session->get('netzhirschFreeGoodToBasketFreedGoodsAdded');

        $config = $this->getConfig();
        if (count($freeGoods) == 1 || $config["nnwebFreeGoodToBasket_insertAllArticles"]) {
            foreach ($freeGoods as $freeGood) {
                $orderNumber = $freeGood['ordernumber'];
                $promotionId = $freeGood['promotionId'];

                // refresh of basket not add more of this good to the basket
                $isFreeGoodInBasket = false;
                foreach ($basket['content'] as $basketArticle) {
                    if ($basketArticle['ordernumber'] == $orderNumber) {
                        $isFreeGoodInBasket = true;
                        break;
                    }
                }

                // save in session so user can delete the free good and it not added anymore
                if (!$isFreeGoodInBasket && !in_array($orderNumber,$netzhirschFreeGoodToBasketFreedGoodsDeleted)) {
                    if (!in_array($orderNumber,$netzhirschFreeGoodToBasketFreedGoodsAdded))
                        $netzhirschFreeGoodToBasketFreedGoodsAdded[] = $orderNumber;
                    Shopware()->Container()
                        ->get('swag_promotion.service.free_goods_service')
                            ->addArticleAsFreeGood($orderNumber, $promotionId);
                }
            }
        }

        Shopware()->Session()->offsetSet('netzhirschFreeGoodToBasketFreedGoodsAdded',$netzhirschFreeGoodToBasketFreedGoodsAdded);
    }

    private function getFreedGoods($basket,$session)
    {
        $promotionSelector = Shopware()->Container()->get('swag_promotion.promotion_selector');
        $contextService = Shopware()->Container()->get('shopware_storefront.context_service');

        $appliedPromotions = $promotionSelector->apply(
            $basket,
            $contextService->getShopContext()->getCurrentCustomerGroup()->getId(),
            $session->get('sUserId'),
            $contextService->getShopContext()->getShop()->getId(),
            array_keys($session->get('promotionVouchers')) ?: []
        );

        $productService = Shopware()->Container()->get('swag_promotion.service.article_service');
        $freeGoods = [];
        foreach ($appliedPromotions->freeGoodsArticlesIds as $promotionId => $freeGoodsArticles) {
            $articlesData = $productService->getFreeGoods($freeGoodsArticles, $promotionId);
            if (empty($freeGoods)) {
                $freeGoods = $articlesData;
            } else {
                $freeGoods = array_merge($freeGoods, $articlesData);
            }
        }
        return $freeGoods;
    }

    private function getConfig() {
        $shop = false;
        if ($this->container->initialized('shop')) {
            $shop = $this->container->get('shop');
        }

        if (!$shop) {
            $shop = $this->container->get('models')->getRepository(Shop::class)->getActiveDefault();
        }

        return $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName($this->getName(), $shop);
    }
}