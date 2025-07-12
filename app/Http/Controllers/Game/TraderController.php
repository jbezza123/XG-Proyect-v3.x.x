<?php

namespace App\Http\Controllers\Game;

use App\Core\BaseController;
use App\Libraries\FormatLib as Format;
use App\Libraries\Functions;
use App\Libraries\Game\ResourceMarket;
use App\Libraries\Users;
use App\Models\Game\Trader;
use App\Models\Game\Auction;


class TraderController extends BaseController
{
    public const MODULE_ID = 5;
    public const RESOURCES = ['metal', 'crystal', 'deuterium'];
    public const PERCENTAGES = [10, 50, 100];

    private ?ResourceMarket $trader;
    private string $error = '';
    private Trader $traderModel;

    public function __construct()
    {
        parent::__construct();
        Users::checkSession();
        parent::loadLang(['game/global', 'game/trader']);
        $this->traderModel = new Trader();
        $this->setUpTrader();
    }

    public function index(): void
    {
        // Handle AJAX module loading
        if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
            $mode = $_GET['mode'] ?? '';

            switch ($mode) {
                case 'traderResources':
                    echo $this->template->set(
                        'game/trader_resources_view',
                        array_merge(
                            $this->langs->language,
                            ['list_of_resources' => $this->buildResourcesSection()]
                        )
                    );
                    exit;

                case 'traderAuctioneer':
					$auctionModel = new Auction();
					$auction = $auctionModel->getCurrentAuction();
					$bidHistory = $auction ? $auctionModel->getBidHistory($auction->id) : [];

					echo $this->template->set('game/trader_auctioneer_view', array_merge(
						$this->langs->language,
						[
							'auction' => $auction,
							'bidHistory' => $bidHistory,
						]
					));
					exit;


                case 'traderScrap':
                    echo $this->template->set('game/trader_scrap_view', $this->langs->language);
                    exit;

                case 'traderImportExport':
                    echo $this->template->set('game/trader_import_export_view', $this->langs->language);
                    exit;

                default:
                    http_response_code(404);
                    echo 'Invalid trader mode';
                    exit;
            }
        }

        // Standard flow
        Functions::moduleMessage(Functions::isModuleAccesible(self::MODULE_ID));
        $this->runAction();
        $this->buildPage();
    }

    private function setUpTrader(): void
    {
        $this->trader = new ResourceMarket(
            $this->user,
            $this->planet
        );
    }

    private function runAction(): void
{
    $post = filter_input_array(INPUT_POST);
    if ($post) {
        // Resource refill check
        if (preg_match_all('/(' . join('|', self::RESOURCES) . ')-(' . join('|', self::PERCENTAGES) . ')/', key($post))) {
            $this->refillResource(...explode('-', key($post)));
        }
        // Auction bid check
        elseif (isset($post['auction_bid'], $post['auction_id'])) {
            $this->placeAuctionBid((int)$post['auction_id'], (int)$post['auction_bid']);
        }
    }
}
private function placeAuctionBid(int $auctionId, int $bidAmount): void
{
    $auctionModel = new \App\Models\Game\Auction();

    $userId = $this->user['user_id'];

    $result = $auctionModel->placeBid($userId, $auctionId, $bidAmount);

    if ($result === true) {
        // Success: redirect back to auction tab
        Functions::redirect('game.php?page=traderOverview&tab=traderAuctioneer');
    } else {
        $this->error = $result;
    }
}


    private function refillResource(string $resource, int $percentage): void
    {
        if ($this->trader->{'is' . $resource . 'StorageFillable'}($percentage)) {
            if ($this->trader->isRefillPayable($resource, $percentage)) {
                $this->traderModel->refillStorage(
                    $this->trader->{'getPriceToFill' . $percentage . 'Percent'}($resource),
                    $resource,
                    $this->trader->getProjectedResouces($resource, $percentage),
                    $this->user['user_id'],
                    $this->planet['planet_id']
                );

                Functions::redirect('game.php?page=traderOverview&tab=traderResources');

            } else {
                $this->error = $this->langs->line('tr_no_enough_dark_matter');
            }
        } else {
            $this->error = $this->langs->line('tr_no_enough_storage');
        }
    }

    private function buildPage(): void
    {
        $this->page->display(
            $this->template->set(
                'game/trader_overview_view',
                array_merge(
                    $this->langs->language,
                    $this->setMessageDisplay(),
                    $this->getPage()
                )
            )
        );
    }

    private function setMessageDisplay(): array
    {
        $message = [
            'status_message' => [],
        ];

        if ($this->error != '') {
            $message = [
                'status_message' => '',
                '/status_message' => '',
                'error_color' => '#FF0000',
                'error_text' => $this->error,
            ];
        }

        return $message;
    }

    private function getPage(): array
    {
        return []; // The overview view handles AJAX loading dynamically
    }

    private function buildResourcesSection(): array
    {
        $list_of_resources = [];

        foreach (self::RESOURCES as $resource) {
            $list_of_resources[] = array_merge(
                $this->langs->language,
                [
                    'dpath' => DPATH,
                    'resource' => $resource,
                    'resource_name' => $this->langs->line($resource),
                    'current_resource' => Format::shortlyNumber($this->planet['planet_' . $resource]),
                    'max_resource' => Format::shortlyNumber($this->planet['planet_' . $resource . '_max']),
                    'refill_options' => $this->setRefillOptions($resource),
                ]
            );
        }

        return $list_of_resources;
    }

    private function setRefillOptions(string $resource): array
    {
        $refillOptions = [];

        foreach (self::PERCENTAGES as $percentage) {
            $dm_price = $this->trader->{'getPriceToFill' . $percentage . 'Percent'}($resource);

            if (
                !$this->trader->{'is' . ucfirst($resource) . 'StorageFillable'}($percentage)
                || $dm_price == 0
            ) {
                $price = Format::colorRed('-');
                $button = '';
            } else {
                $price = Format::customColor(
                    Format::prettyNumber($dm_price),
                    '#2cbef2'
                ) . ' ' . $this->langs->line('dark_matter_short');
                $button = '<input type="submit" name="' . $resource . '-' . $percentage . '" value="' . $this->langs->line('tr_refill_button') . '">';
            }

            $refillOptions[] = [
                'label' => $this->langs->line('tr_refill_by'),
                'percentage' => $percentage,
                'tr_requires' => $this->langs->line('tr_requires'),
                'price' => $price,
                'button' => $button,
            ];
        }

        return $refillOptions;
    }
}
