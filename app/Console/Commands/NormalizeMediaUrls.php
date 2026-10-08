<?php

namespace App\Console\Commands;

use App\Models\AttributeItem;
use App\Models\Category;
use App\Models\CommunityShop;
use App\Models\ProductImage;
use App\Models\Seller;
use App\Support\MediaUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeMediaUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:normalize-urls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize legacy localhost media URLs in the database to live production base URLs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Normalizing media URLs to live production base: ' . MediaUrl::LIVE_BASE_URL);

        $updatedImages = 0;
        foreach (ProductImage::cursor() as $image) {
            $raw = $image->getRawOriginal('image_url');
            $normalized = MediaUrl::resolve($raw, $image->file_path);
            if ($raw !== $normalized) {
                DB::table('product_images')->where('id', $image->id)->update(['image_url' => $normalized]);
                $updatedImages++;
            }
        }
        $this->info("Updated {$updatedImages} product images.");

        $updatedCategories = 0;
        foreach (Category::cursor() as $category) {
            $raw = $category->getRawOriginal('image_url');
            if ($raw) {
                $normalized = MediaUrl::resolve($raw);
                if ($raw !== $normalized) {
                    DB::table('categories')->where('id', $category->id)->update(['image_url' => $normalized]);
                    $updatedCategories++;
                }
            }
        }
        $this->info("Updated {$updatedCategories} categories.");

        $updatedShops = 0;
        foreach (CommunityShop::cursor() as $shop) {
            $rawLogo = $shop->getRawOriginal('logo_url');
            $rawBanner = $shop->getRawOriginal('banner_url');
            $updates = [];
            if ($rawLogo && ($norm = MediaUrl::resolve($rawLogo)) !== $rawLogo) {
                $updates['logo_url'] = $norm;
            }
            if ($rawBanner && ($norm = MediaUrl::resolve($rawBanner)) !== $rawBanner) {
                $updates['banner_url'] = $norm;
            }
            if (! empty($updates)) {
                DB::table('community_shops')->where('id', $shop->id)->update($updates);
                $updatedShops++;
            }
        }
        $this->info("Updated {$updatedShops} community shops.");

        $updatedSellers = 0;
        foreach (Seller::cursor() as $seller) {
            $rawLogo = $seller->getRawOriginal('logo_url');
            $rawBanner = $seller->getRawOriginal('banner_url');
            $updates = [];
            if ($rawLogo && ($norm = MediaUrl::resolve($rawLogo)) !== $rawLogo) {
                $updates['logo_url'] = $norm;
            }
            if ($rawBanner && ($norm = MediaUrl::resolve($rawBanner)) !== $rawBanner) {
                $updates['banner_url'] = $norm;
            }
            if (! empty($updates)) {
                DB::table('sellers')->where('id', $seller->id)->update($updates);
                $updatedSellers++;
            }
        }
        $this->info("Updated {$updatedSellers} sellers.");

        $updatedAttributes = 0;
        foreach (AttributeItem::cursor() as $attr) {
            $raw = $attr->getRawOriginal('image_url');
            if ($raw) {
                $normalized = MediaUrl::resolve($raw);
                if ($raw !== $normalized) {
                    DB::table('attribute_items')->where('id', $attr->id)->update(['image_url' => $normalized]);
                    $updatedAttributes++;
                }
            }
        }
        $this->info("Updated {$updatedAttributes} attribute items.");

        $this->info('URL normalization completed successfully.');
        return self::SUCCESS;
    }
}
