<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RestaurantDemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'owner@biryanihouse.test'],
            ['name' => 'Kamran Sheikh', 'password' => bcrypt('password')]
        );
        if (! $owner->hasRole('vendor_owner')) {
            $owner->assignRole('vendor_owner');
        }

        $vendor = Vendor::updateOrCreate(
            ['owner_user_id' => $owner->id],
            [
                'business_name' => 'Biryani House',
                'business_type' => 'restaurant',
                'vendor_package_id' => VendorPackage::where('slug', 'standard')->value('id'),
                'status' => 'approved',
                'approved_at' => now(),
                'notification_phone' => '+923001234567',
                'default_currency' => 'PKR',
                'timezone' => 'Asia/Karachi',
            ]
        );

        if ($owner->vendor_id !== $vendor->id) {
            $owner->update(['vendor_id' => $vendor->id]);
        }

        $stores = [
            [
                'name' => 'Biryani House - DHA Phase 6',
                'slug' => 'dha-phase-6',
                'is_primary' => true,
                'city' => 'Karachi',
                'address' => 'Khayaban-e-Sehar, DHA Phase 6',
                'lat' => 24.8138,
                'lng' => 67.0625,
                'hours' => ['open' => '12:00', 'close' => '23:30'],
            ],
            [
                'name' => 'Biryani House - Gulshan-e-Iqbal',
                'slug' => 'gulshan-e-iqbal',
                'is_primary' => false,
                'city' => 'Karachi',
                'address' => 'Block 13-D, Gulshan-e-Iqbal',
                'lat' => 24.9180,
                'lng' => 67.0970,
                'hours' => ['open' => '11:30', 'close' => '23:00'],
            ],
        ];

        $storeModels = [];
        foreach ($stores as $s) {
            $store = Store::updateOrCreate(
                ['vendor_id' => $vendor->id, 'slug' => $s['slug']],
                [
                    'name' => $s['name'],
                    'is_active' => true,
                    'is_primary' => $s['is_primary'],
                    'working_hours' => [
                        'mon' => $s['hours'], 'tue' => $s['hours'], 'wed' => $s['hours'],
                        'thu' => $s['hours'], 'fri' => $s['hours'], 'sat' => $s['hours'], 'sun' => $s['hours'],
                    ],
                ]
            );

            StoreLocation::updateOrCreate(
                ['store_id' => $store->id],
                [
                    'address_line' => $s['address'],
                    'city' => $s['city'],
                    'latitude' => $s['lat'],
                    'longitude' => $s['lng'],
                ]
            );

            $storeModels[] = $store;
        }

        $categories = [
            'Appetizers' => ['appetizers'],
            'Biryani & Rice' => ['biryani-rice'],
            'BBQ & Grill' => ['bbq-grill'],
            'Karahi & Curry' => ['karahi-curry'],
            'Burgers & Sandwiches' => ['burgers-sandwiches'],
            'Beverages' => ['beverages'],
            'Desserts' => ['desserts'],
        ];

        $categoryModels = [];
        foreach ($categories as $name => [$slug]) {
            $categoryModels[$name] = ProductCategory::updateOrCreate(
                ['vendor_id' => $vendor->id, 'slug' => $slug],
                ['name' => $name, 'is_active' => true]
            );
        }

        $products = [
            'Appetizers' => [
                ['Chicken Seekh Kebab (6 pcs)', 450, 'Juicy minced chicken skewers grilled over charcoal, served with mint chutney.'],
                ['Vegetable Spring Rolls (8 pcs)', 350, 'Crispy fried rolls stuffed with spiced mixed vegetables.'],
                ['Chicken Wings (8 pcs)', 550, 'Spicy marinated wings, deep fried and tossed in tandoori sauce.'],
                ['Dahi Bhalla', 300, 'Soft lentil dumplings soaked in yogurt with tamarind and mint chutney.'],
            ],
            'Biryani & Rice' => [
                ['Chicken Biryani (Full)', 650, 'Fragrant basmati rice layered with spiced chicken, served with raita and salad.'],
                ['Chicken Biryani (Half)', 400, 'Half portion of our signature chicken biryani.'],
                ['Mutton Biryani (Full)', 950, 'Slow-cooked mutton biryani with aromatic spices and saffron rice.'],
                ['Vegetable Pulao', 400, 'Basmati rice cooked with mixed vegetables and whole spices.'],
                ['Chicken Tikka Boti Rice', 600, 'Steamed rice topped with grilled chicken tikka boti pieces.'],
            ],
            'BBQ & Grill' => [
                ['Chicken Tikka (Full)', 900, 'Charcoal-grilled chicken tikka marinated overnight in yogurt and spices.'],
                ['Beef Boti (Half)', 700, 'Tender beef boti pieces grilled to perfection.'],
                ['Malai Boti (8 pcs)', 750, 'Creamy, mild chicken boti grilled on skewers.'],
                ['Mixed Grill Platter', 1800, 'Assortment of seekh kebab, chicken tikka, and beef boti with naan.'],
            ],
            'Karahi & Curry' => [
                ['Chicken Karahi (Full)', 1400, 'Traditional wok-cooked chicken curry with tomatoes, ginger, and green chilies.'],
                ['Mutton Karahi (Full)', 2200, 'Rich mutton karahi cooked in a traditional clay-pot style gravy.'],
                ['Daal Makhani', 450, 'Slow-cooked black lentils in a creamy buttery gravy.'],
                ['Chicken White Karahi (Full)', 1500, 'Creamy white karahi made with yogurt, cream, and green chilies.'],
            ],
            'Burgers & Sandwiches' => [
                ['Zinger Burger', 450, 'Crispy fried chicken fillet burger with lettuce and mayo.'],
                ['Beef Burger', 500, 'Grilled beef patty burger with cheese and special sauce.'],
                ['Club Sandwich', 550, 'Triple-layered sandwich with chicken, egg, and vegetables.'],
            ],
            'Beverages' => [
                ['Fresh Lime Soda', 200, 'Refreshing lime soda, sweet or salted.'],
                ['Mango Lassi', 300, 'Creamy yogurt shake blended with fresh mango pulp.'],
                ['Soft Drink (Can)', 120, 'Chilled Coke, Pepsi, Sprite, or Fanta.'],
                ['Kashmiri Chai', 250, 'Pink tea topped with crushed pistachios and almonds.'],
            ],
            'Desserts' => [
                ['Gulab Jamun (4 pcs)', 300, 'Soft milk-solid dumplings soaked in rose-flavored sugar syrup.'],
                ['Kheer', 280, 'Traditional rice pudding garnished with almonds and pistachios.'],
                ['Shahi Tukray', 350, 'Fried bread soaked in sweetened milk, topped with rabri and nuts.'],
            ],
        ];

        foreach ($storeModels as $store) {
            foreach ($products as $categoryName => $items) {
                $category = $categoryModels[$categoryName];

                foreach ($items as [$name, $price, $description]) {
                    $slug = Str::slug($store->slug.'-'.$name);

                    $product = Product::updateOrCreate(
                        ['store_id' => $store->id, 'slug' => $slug],
                        [
                            'vendor_id' => $vendor->id,
                            'category_id' => $category->id,
                            'name' => $name,
                            'description' => $description,
                            'base_price' => $price,
                            'sku' => strtoupper(Str::slug($name, '')).'-'.$store->id,
                            'is_active' => true,
                            'ai_search_keywords' => strtolower($categoryName.' '.$name),
                        ]
                    );

                    ProductImage::updateOrCreate(
                        ['product_id' => $product->id, 'sort_order' => 0],
                        [
                            'path' => 'products/placeholder-'.Str::slug($categoryName).'.jpg',
                            'is_primary' => true,
                        ]
                    );

                    Inventory::updateOrCreate(
                        ['store_id' => $store->id, 'product_id' => $product->id, 'product_variant_id' => null],
                        [
                            'quantity' => rand(15, 60),
                            'low_stock_threshold' => 5,
                            'track_stock' => true,
                        ]
                    );
                }
            }
        }
    }
}
