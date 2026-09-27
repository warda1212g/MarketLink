<?php
/**
 * Demo data. Run: php database/seed_marketplace.php [--fresh]
 *
 * Makes one market and three stalls for every market category, plus some customers,
 * orders and reviews. Seed accounts use @seed.marketlink.pk emails, password Password@123.
 * --fresh deletes the old seed data first.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run from the command line: php database/seed_marketplace.php');
}
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 3);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';

const SEED_DOMAIN = 'seed.marketlink.pk';
const SEED_PASSWORD = 'Password@123';
mt_srand(20260926);

/* catalogue: market_type_id => data */
// product: [name, unit, price, stock, image search query, category id (optional override)]
$CATALOGUE = [
    1 => ['market' => ['Akbari Grain Mandi', 'Lahore', 'Akbari Mandi', 31.5793, 74.3237], 'q' => 'wheat grain harvest', 'products' => [
        ['Super Kernel Basmati Rice', 'kg', 340, 400, 'basmati rice grains'], ['Sella Basmati Rice', 'kg', 310, 350, 'parboiled rice'],
        ['Wheat (Gandum)', 'kg', 115, 900, 'wheat grains'], ['Chakki Atta', 'kg', 135, 500, 'whole wheat flour'],
        ['Yellow Maize (Makai)', 'kg', 95, 600, 'yellow corn kernels'], ['Barley (Jau)', 'kg', 160, 200, 'barley grains'],
        ['Pearl Millet (Bajra)', 'kg', 150, 250, 'pearl millet'], ['Sorghum (Jowar)', 'kg', 170, 220, 'sorghum grain'],
        ['Brown Rice', 'kg', 380, 150, 'brown rice'], ['Broken Rice (Tota)', 'kg', 180, 300, 'broken rice'],
        ['Corn Cobs (Challi)', 'dozen', 360, 80, 'corn on the cob'], ['Oats', 'kg', 520, 90, 'rolled oats'],
    ]],
    2 => ['market' => ['Sabzi Mandi Karachi', 'Karachi', 'Super Highway', 24.9312, 67.1612], 'q' => 'vegetable market stall', 'products' => [
        ['Tomatoes', 'kg', 160, 300, 'fresh tomatoes'], ['Red Onions', 'kg', 140, 400, 'red onions'], ['Potatoes', 'kg', 90, 500, 'potatoes pile'],
        ['Okra (Bhindi)', 'kg', 220, 120, 'okra vegetable'], ['Spinach (Palak)', 'bundle', 60, 150, 'spinach leaves'],
        ['Cauliflower (Phool Gobi)', 'piece', 120, 100, 'cauliflower'], ['Bitter Gourd (Karela)', 'kg', 200, 90, 'bitter gourd'],
        ['Green Chillies', 'kg', 240, 80, 'green chilli peppers'], ['Carrots (Gajar)', 'kg', 110, 200, 'carrots bunch'],
        ['Eggplant (Baingan)', 'kg', 130, 150, 'eggplant aubergine'], ['Green Peas (Matar)', 'kg', 260, 120, 'green peas pods'],
        ['Cucumber (Kheera)', 'kg', 100, 180, 'cucumbers'], ['Bottle Gourd (Lauki)', 'piece', 80, 110, 'bottle gourd'], ['Turnips (Shalgam)', 'kg', 90, 140, 'turnips'],
    ]],
    3 => ['market' => ['Multan Fruit Mandi', 'Multan', 'Vehari Road', 30.1798, 71.4899], 'q' => 'fruit market mangoes', 'products' => [
        ['Sindhri Mangoes', 'kg', 280, 300, 'mangoes'], ['Chaunsa Mangoes', 'kg', 320, 300, 'ripe mango fruit'], ['Kinnow', 'dozen', 360, 200, 'kinnow mandarin oranges'],
        ['Guava (Amrood)', 'kg', 180, 150, 'guava fruit'], ['Bananas', 'dozen', 170, 200, 'bananas bunch'], ['Pomegranate (Anaar)', 'kg', 420, 120, 'pomegranate'],
        ['Kala Kulu Apples', 'kg', 300, 180, 'red apples'], ['Aseel Dates', 'kg', 650, 100, 'dates fruit'], ['Sundar Khani Grapes', 'kg', 380, 90, 'green grapes'],
        ['Watermelon', 'piece', 450, 60, 'watermelon'], ['Apricots (Khubani)', 'kg', 520, 70, 'apricots'], ['Peaches (Aaru)', 'kg', 340, 90, 'peaches fruit'],
        ['Loquat (Lokat)', 'kg', 260, 80, 'loquat fruit'], ['Papaya', 'kg', 200, 70, 'papaya fruit'],
    ]],
    4 => ['market' => ['Punjab Oilseed Market', 'Faisalabad', 'Jhang Road', 31.4180, 73.0790], 'q' => 'sunflower field', 'products' => [
        ['Sunflower Seeds', 'kg', 260, 300, 'sunflower seeds'], ['Mustard Seeds (Sarson)', 'kg', 240, 250, 'mustard seeds'], ['Canola Seeds', 'kg', 250, 250, 'canola rapeseed'],
        ['Sesame Seeds (Til)', 'kg', 620, 120, 'sesame seeds'], ['Soybeans', 'kg', 280, 200, 'soybeans'], ['Groundnuts (Moongphali)', 'kg', 540, 150, 'peanuts in shell'],
        ['Flaxseed (Alsi)', 'kg', 450, 100, 'flax seeds linseed'], ['Cold-Pressed Mustard Oil', 'litre', 780, 80, 'mustard oil bottle'],
        ['Cold-Pressed Sesame Oil', 'litre', 1350, 50, 'sesame oil'], ['Castor Seeds', 'kg', 300, 90, 'castor beans seeds'],
    ]],
    5 => ['market' => ['Jodia Bazaar Daal Market', 'Karachi', 'Jodia Bazaar', 24.8568, 66.9994], 'q' => 'lentils market sacks', 'products' => [
        ['Chana Daal', 'kg', 290, 300, 'chana dal split chickpeas'], ['Masoor Daal', 'kg', 330, 300, 'red lentils'], ['Moong Daal', 'kg', 360, 250, 'mung beans'],
        ['Mash Daal (Urad)', 'kg', 480, 200, 'black gram urad'], ['White Chickpeas (Kabuli Chana)', 'kg', 390, 250, 'chickpeas'],
        ['Black Chickpeas (Kala Chana)', 'kg', 280, 200, 'black chickpeas'], ['Red Kidney Beans (Lobia Surkh)', 'kg', 520, 150, 'red kidney beans'],
        ['Black-Eyed Peas (Lobia)', 'kg', 420, 150, 'black eyed peas'], ['Whole Masoor', 'kg', 310, 160, 'brown lentils'], ['Green Split Peas', 'kg', 260, 120, 'split peas'],
    ]],
    6 => ['market' => ['Kunri Spice Market', 'Kunri', 'Main Bazaar', 25.1770, 69.5680], 'q' => 'spices market colourful', 'products' => [
        ['Kunri Red Chillies', 'kg', 950, 120, 'dried red chillies'], ['Turmeric (Haldi)', 'kg', 680, 100, 'turmeric root powder'], ['Coriander Seeds (Dhania)', 'kg', 420, 120, 'coriander seeds'],
        ['Cumin (Zeera)', 'kg', 1600, 60, 'cumin seeds'], ['Fresh Mint (Podina)', 'bundle', 40, 200, 'fresh mint leaves'], ['Fresh Coriander', 'bundle', 40, 200, 'fresh coriander cilantro'],
        ['Ginger (Adrak)', 'kg', 650, 90, 'ginger root'], ['Garlic (Lehsan)', 'kg', 560, 100, 'garlic bulbs'], ['Green Cardamom (Elaichi)', 'g', 9, 3000, 'green cardamom pods'],
        ['Black Pepper (Kali Mirch)', 'kg', 2400, 40, 'black peppercorns'], ['Cinnamon (Darchini)', 'kg', 1800, 40, 'cinnamon sticks'], ['Cloves (Laung)', 'g', 6, 2500, 'cloves spice'],
    ]],
    7 => ['market' => ['Sukkur Cash Crop Mandi', 'Sukkur', 'Bypass Road', 27.7052, 68.8574], 'q' => 'sugarcane field', 'products' => [
        ['Sugarcane (Ganna)', 'bundle', 450, 150, 'sugarcane stalks'], ['Raw Cotton (Phutti)', 'kg', 210, 800, 'cotton bolls'], ['Tobacco Leaves', 'kg', 900, 100, 'tobacco leaves drying'],
        ['Jaggery (Gurr)', 'kg', 260, 250, 'jaggery'], ['Raw Sugar (Shakkar)', 'kg', 240, 250, 'brown raw sugar'], ['Green Tea Leaves', 'kg', 1500, 60, 'green tea leaves plantation'],
        ['Jute Fibre', 'kg', 180, 150, 'jute fibre'], ['Fresh Sugarcane Juice', 'litre', 150, 60, 'sugarcane juice glass'], ['Cotton Sticks (Chhari)', 'bundle', 300, 80, 'cotton plant stalks'],
    ]],
    8 => ['market' => ['Phool Mandi Lahore', 'Lahore', 'Mall Road', 31.5563, 74.3190], 'q' => 'flower market bouquets', 'products' => [
        ['Red Roses', 'dozen', 600, 120, 'red roses bouquet'], ['Motia (Jasmine) Gajray', 'piece', 150, 200, 'jasmine flowers'], ['Marigold (Genda) Garlands', 'piece', 250, 150, 'marigold garland'],
        ['Tuberose (Rajnigandha)', 'bundle', 400, 80, 'tuberose flowers'], ['Gladiolus', 'dozen', 700, 60, 'gladiolus flowers'], ['Chrysanthemums', 'bundle', 350, 90, 'chrysanthemum flowers'],
        ['Rose Petals (Pattiyan)', 'kg', 900, 50, 'rose petals'], ['Wedding Sehra', 'piece', 2500, 20, 'flower garland wedding'], ['White Lilies', 'dozen', 1200, 40, 'white lilies'],
        ['Sunflowers (Cut)', 'dozen', 800, 40, 'cut sunflowers'], ['Gerbera Daisies', 'dozen', 900, 50, 'gerbera daisy'],
    ]],
    9 => ['market' => ['Changa Manga Timber Market', 'Kasur', 'Changa Manga', 31.0833, 73.9667], 'q' => 'forest trees plantation', 'products' => [
        ['Shisham Sapling', 'piece', 150, 300, 'dalbergia sissoo tree'], ['Kikar (Acacia) Sapling', 'piece', 100, 300, 'acacia tree'], ['Neem Sapling', 'piece', 180, 250, 'neem tree'],
        ['Eucalyptus (Safeda) Sapling', 'piece', 90, 400, 'eucalyptus trees'], ['Poplar Sapling', 'piece', 120, 300, 'poplar trees'], ['Chir Pine Sapling', 'piece', 250, 150, 'pine seedling'],
        ['Deodar Cedar Sapling', 'piece', 450, 100, 'deodar cedar'], ['Firewood (Lakri)', 'kg', 45, 3000, 'firewood stack'], ['Shisham Timber Planks', 'piece', 6500, 40, 'wooden planks lumber'],
        ['Mulberry (Shehtoot) Sapling', 'piece', 200, 150, 'mulberry tree'], ['Jamun Sapling', 'piece', 220, 120, 'jamun tree'],
    ]],
    10 => ['market' => ['Jallo Nursery Market', 'Lahore', 'Jallo Park', 31.5816, 74.4803], 'q' => 'plant nursery pots', 'products' => [
        ['Money Plant', 'piece', 350, 80, 'pothos money plant pot'], ['Snake Plant', 'piece', 900, 60, 'snake plant sansevieria'], ['Aloe Vera Pot', 'piece', 450, 70, 'aloe vera potted plant'],
        ['Bougainvillea', 'piece', 600, 60, 'bougainvillea'], ['Lemon Plant', 'piece', 1200, 40, 'lemon tree pot'], ['Desi Rose Plant', 'piece', 500, 70, 'rose bush pot'],
        ['Tulsi (Holy Basil)', 'piece', 300, 90, 'holy basil plant'], ['Areca Palm', 'piece', 1800, 30, 'areca palm'], ['Jade Plant', 'piece', 700, 50, 'jade plant crassula'],
        ['Chilli Seedlings Tray', 'pack', 400, 60, 'seedlings tray'], ['Peace Lily', 'piece', 1400, 40, 'peace lily'], ['Tomato Seedlings Tray', 'pack', 380, 60, 'tomato seedlings'],
    ]],
    11 => ['market' => ['Sohrab Goth Mawaishi Mandi', 'Karachi', 'Sohrab Goth', 24.9486, 67.1053], 'q' => 'cattle market livestock', 'products' => [
        ['Sahiwal Cow', 'piece', 285000, 6, 'sahiwal cow', 15], ['Cholistani Bull', 'piece', 340000, 4, 'zebu bull', 15], ['Nili-Ravi Buffalo', 'piece', 420000, 4, 'water buffalo', 16],
        ['Beetal Goat', 'piece', 95000, 10, 'beetal goat', 17], ['Teddy Goat', 'piece', 45000, 15, 'small goat kid', 17], ['Kajli Sheep', 'piece', 75000, 10, 'sheep ram', 18],
        ['Lohi Sheep', 'piece', 60000, 10, 'sheep flock', 18], ['Marecha Camel', 'piece', 650000, 2, 'camel', 19], ['Fresh Cow Milk', 'litre', 220, 150, 'fresh milk', 15],
        ['Fresh Buffalo Milk', 'litre', 240, 150, 'milk bucket dairy', 16], ['Desi Ghee', 'kg', 2600, 40, 'ghee clarified butter', 16], ['Goat Milk', 'litre', 300, 40, 'goat milking', 17],
    ]],
    12 => ['market' => ['Bakra Mandi Poultry Market', 'Rawalpindi', 'Pirwadhai', 33.6329, 73.0512], 'q' => 'chickens farm', 'products' => [
        ['Desi Eggs', 'dozen', 480, 150, 'brown eggs'], ['Farm Eggs', 'dozen', 330, 300, 'eggs tray'], ['Desi Chicken (Live)', 'piece', 1600, 60, 'free range chicken hen'],
        ['Broiler Chicken (Live)', 'kg', 520, 200, 'broiler chickens'], ['Ducks (Batakh)', 'piece', 2200, 30, 'domestic duck'], ['Quail (Bater)', 'piece', 350, 100, 'quail bird'],
        ['Turkey', 'piece', 9000, 10, 'turkey bird farm'], ['Aseel Rooster', 'piece', 6500, 12, 'rooster'], ['Duck Eggs', 'dozen', 600, 60, 'duck eggs'], ['Quail Eggs', 'dozen', 250, 80, 'quail eggs'],
    ]],
    13 => ['market' => ['Karachi Fish Harbour', 'Karachi', 'West Wharf', 24.8452, 66.9819], 'q' => 'fish market harbour', 'products' => [
        ['Rohu Fish', 'kg', 650, 120, 'rohu fish'], ['Thaila Fish', 'kg', 700, 100, 'carp fish'], ['Pomfret (Paplet)', 'kg', 2400, 50, 'pomfret fish'],
        ['Tiger Prawns (Jheenga)', 'kg', 3200, 40, 'tiger prawns'], ['Swat Trout', 'kg', 2600, 30, 'rainbow trout'], ['Tilapia', 'kg', 750, 80, 'tilapia fish'],
        ['Singhara (Catfish)', 'kg', 1100, 60, 'catfish'], ['Surmai (King Fish)', 'kg', 2200, 50, 'king mackerel fish'], ['Mud Crabs (Kekra)', 'kg', 1800, 30, 'mud crabs'],
        ['Hilsa (Palla)', 'kg', 2800, 30, 'hilsa fish'], ['Squid', 'kg', 1400, 30, 'squid seafood'], ['Lobster', 'kg', 6500, 10, 'lobster'],
    ]],
    14 => ['market' => ['Swat Honey Bazaar', 'Mingora', 'Green Chowk', 34.7717, 72.3600], 'q' => 'beekeeping hives', 'products' => [
        ['Sidr (Beri) Honey', 'kg', 3800, 60, 'honey jar'], ['Acacia (Phulai) Honey', 'kg', 2600, 80, 'golden honey'], ['Wild Mountain Honey', 'kg', 4500, 40, 'wild honey'],
        ['Orange Blossom Honey', 'kg', 2200, 60, 'honey dipper'], ['Raw Honeycomb', 'kg', 5200, 25, 'honeycomb'], ['Pure Beeswax', 'kg', 1800, 30, 'beeswax'],
        ['Bee Pollen', 'g', 8, 3000, 'bee pollen'], ['Royal Jelly', 'g', 45, 800, 'royal jelly'], ['Propolis', 'g', 30, 800, 'bee propolis'], ['Honey Gift Box', 'box', 4800, 25, 'honey jars gift'],
    ]],
    15 => ['market' => ['Peshawar Jari Booti Bazaar', 'Peshawar', 'Qissa Khwani', 34.0086, 71.5785], 'q' => 'herbal medicine market', 'products' => [
        ['Moringa (Sohanjna) Powder', 'kg', 1400, 40, 'moringa powder'], ['Ajwain (Carom Seeds)', 'kg', 900, 50, 'carom seeds ajwain'], ['Kalonji (Nigella Seeds)', 'kg', 1100, 50, 'nigella seeds'],
        ['Isabgol (Psyllium Husk)', 'kg', 1600, 40, 'psyllium husk'], ['Stevia Leaves', 'kg', 2500, 20, 'stevia plant'], ['Aloe Vera Leaves', 'kg', 250, 80, 'aloe vera leaves'],
        ['Ashwagandha Root', 'kg', 2800, 20, 'ashwagandha root'], ['Fennel Seeds (Saunf)', 'kg', 700, 60, 'fennel seeds'], ['Fenugreek Seeds (Methi)', 'kg', 450, 60, 'fenugreek seeds'],
        ['Senna Leaves (Sana Makki)', 'kg', 900, 30, 'senna leaves'], ['Chamomile (Babuna)', 'kg', 3200, 15, 'chamomile flowers dried'], ['Liquorice Root (Mulethi)', 'kg', 1500, 25, 'liquorice root'],
    ]],
];

// Three stalls per market category: [stall name, owner, city, description]
$VENDORS = [
    1 => [['Chaudhry Grain Traders', 'Chaudhry Aslam Warraich', 'Lahore'], ['Malik Rice & Atta House', 'Malik Tariq Mehmood', 'Gujranwala'], ['Sheikhupura Anaaj Ghar', 'Rana Imran Khan', 'Sheikhupura']],
    2 => [['Haji Rasheed Sabzi Wala', 'Haji Abdul Rasheed', 'Karachi'], ['Green Valley Farms', 'Syed Kamran Ali', 'Hyderabad'], ['Memon Fresh Vegetables', 'Muhammad Farooq Memon', 'Karachi']],
    3 => [['Ahmed Fresh Fruits', 'Ahmed Raza Qureshi', 'Multan'], ['Al-Madina Fruit Stall', 'Muhammad Usman Bhatti', 'Multan'], ['Sindhri Mango Orchards', 'Ghulam Mustafa Jatoi', 'Mirpur Khas']],
    4 => [['Faisalabad Oil Mills', 'Sardar Naveed Akhtar', 'Faisalabad'], ['Sarson Da Kheit', 'Jutt Zulfiqar Ali', 'Sargodha'], ['Punjab Seeds Co.', 'Mian Shahid Rafiq', 'Okara']],
    5 => [['Bismillah Daal Traders', 'Abdul Majeed Paracha', 'Karachi'], ['Qureshi Pulses', 'Nadeem Qureshi', 'Karachi'], ['Thal Chana Growers', 'Ghulam Abbas Niazi', 'Bhakkar']],
    6 => [['Kunri Mirch Wala', 'Arbab Sikandar Hingorjo', 'Kunri'], ['Hazara Masala House', 'Sajid Awan', 'Abbottabad'], ['Lahori Spice Bazaar', 'Khawaja Bilal Ahmed', 'Lahore']],
    7 => [['Sukkur Sugarcane Farms', 'Syed Ali Nawaz Shah', 'Sukkur'], ['Rahim Yar Khan Cotton Co.', 'Jam Kamal Din', 'Rahim Yar Khan'], ['Mansehra Tea Estate', 'Qazi Haroon Swati', 'Mansehra']],
    8 => [['Gulshan-e-Phool', 'Rukhsana Bibi', 'Lahore'], ['Pattoki Flower Farms', 'Ch. Ejaz Ahmad', 'Pattoki'], ['Motia Gajra Corner', 'Shazia Parveen', 'Karachi']],
    9 => [['Changa Manga Nursery', 'Malik Asghar Dogar', 'Kasur'], ['Murree Pine Traders', 'Raja Waqas Abbasi', 'Murree'], ['Kaghan Timber Mart', 'Khan Bahadur Swati', 'Balakot']],
    10 => [['Jallo Green House', 'Tahira Yasmin', 'Lahore'], ['Islamabad Plant Hub', 'Faisal Nawaz Kiani', 'Islamabad'], ['Hari Bhari Nursery', 'Adeel Siddiqui', 'Karachi']],
    11 => [['Sahiwal Cattle Farm', 'Ch. Mushtaq Ahmed Gondal', 'Sahiwal'], ['Nili-Ravi Dairy & Livestock', 'Malik Sohail Khokhar', 'Pakpattan'], ['Baloch Mawaishi Center', 'Sardar Akbar Bugti', 'Quetta']],
    12 => [['Desi Murghi Farm', 'Imtiaz Hussain Shah', 'Rawalpindi'], ['Chakwal Poultry House', 'Zafar Iqbal Awan', 'Chakwal'], ['Aseel Birds Corner', 'Mirza Kashif Baig', 'Lahore']],
    13 => [['Karachi Sea Catch', 'Haji Yousuf Baloch', 'Karachi'], ['Swat Trout Farm', 'Fazal Rahim Yousafzai', 'Madyan'], ['Keenjhar Fish Point', 'Allah Bux Mallah', 'Thatta']],
    14 => [['Swat Valley Honey', 'Gul Muhammad Khan', 'Mingora'], ['Karak Beri Honey', 'Haji Sher Zaman Khattak', 'Karak'], ['Pothohar Bee Farm', 'Ansar Mehmood Satti', 'Rawalpindi']],
    15 => [['Hakeem Sahib Dawakhana', 'Hakeem Abdul Wahab', 'Peshawar'], ['Cholistan Herbal Farm', 'Muhammad Akram Laar', 'Bahawalpur'], ['Shifa Jari Booti', 'Dr. Nasreen Akhtar', 'Lahore']],
];

$CUSTOMERS = ['Ali Khan', 'Sara Ahmed', 'Ayesha Siddiqui', 'Hamza Malik', 'Fatima Zahra', 'Bilal Chaudhry', 'Zainab Hussain', 'Usman Tariq', 'Hira Baig', 'Omar Farooq', 'Mehwish Riaz', 'Danish Iqbal'];
$REVIEWS = [
    5 => ['Bohat fresh maal tha, pickup bhi time par ready tha. Highly recommended!', 'Excellent quality - exactly as described. Will order again InshaAllah.', 'Great service, owner was very polite and helpful.', 'Best in the market, prices bhi reasonable hain.'],
    4 => ['Achi quality, thora late ready hua lekin overall good.', 'Good stuff, packaging aur behtar ho sakti hai.', 'Fresh and good value for money.'],
    3 => ['Theek tha, some items were not as fresh as expected.', 'Average experience, pickup mein wait karna para.'],
];
$CITY_COORDS = [
    'Lahore' => [31.5204, 74.3587], 'Gujranwala' => [32.1877, 74.1945], 'Sheikhupura' => [31.7167, 73.9850], 'Karachi' => [24.8607, 67.0011],
    'Hyderabad' => [25.3960, 68.3578], 'Multan' => [30.1575, 71.5249], 'Mirpur Khas' => [25.5276, 69.0111], 'Faisalabad' => [31.4504, 73.1350],
    'Sargodha' => [32.0836, 72.6711], 'Okara' => [30.8138, 73.4534], 'Bhakkar' => [31.6333, 71.0667], 'Kunri' => [25.1770, 69.5680],
    'Abbottabad' => [34.1688, 73.2215], 'Sukkur' => [27.7052, 68.8574], 'Rahim Yar Khan' => [28.4202, 70.2952], 'Mansehra' => [34.3302, 73.1968],
    'Pattoki' => [31.0214, 73.8500], 'Kasur' => [31.1187, 74.4630], 'Murree' => [33.9070, 73.3943], 'Balakot' => [34.5489, 73.3505],
    'Islamabad' => [33.6844, 73.0479], 'Sahiwal' => [30.6682, 73.1114], 'Pakpattan' => [30.3436, 73.3884], 'Quetta' => [30.1798, 66.9750],
    'Rawalpindi' => [33.5651, 73.0169], 'Chakwal' => [32.9328, 72.8630], 'Madyan' => [35.1400, 72.5400], 'Thatta' => [24.7461, 67.9235],
    'Mingora' => [34.7717, 72.3600], 'Karak' => [33.1167, 71.0833], 'Peshawar' => [34.0151, 71.5249], 'Bahawalpur' => [29.3544, 71.6911],
];

/* images (Openverse, cached, never reused) */
$cacheFile = APP_ROOT . '/storage/cache/seed-images.json';
$cache = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];
$usedImages = [];

function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'MarketLink/1.0 (demo seeder; admin@marketlink.local)']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : ''];
}

/** Candidate image results for a search query (cached). */
function image_candidates(string $q): array
{
    global $cache, $cacheFile;
    if (isset($cache[$q])) return $cache[$q];
    usleep(700000);
    [$code, $body] = http_get('https://api.openverse.org/v1/images/?' . http_build_query(['q' => $q, 'page_size' => 12, 'mature' => 'false']));
    if ($code === 429) { echo "   (rate limited, waiting 30s)\n"; sleep(30); [$code, $body] = http_get('https://api.openverse.org/v1/images/?' . http_build_query(['q' => $q, 'page_size' => 12])); }
    $data = json_decode($body, true);
    $list = [];
    foreach ($data['results'] ?? [] as $r) {
        $list[] = ['id' => $r['id'], 'thumb' => 'https://api.openverse.org/v1/images/' . $r['id'] . '/thumb/', 'url' => $r['url'] ?? null];
    }
    if ($code === 200) {
        $cache[$q] = $list;
        file_put_contents($cacheFile, json_encode($cache));
    }
    return $list;
}

/** Download a not-yet-used image for $q into uploads/$folder; returns the relative path or null. */
function seed_image(string $q, string $folder): ?string
{
    global $usedImages;
    foreach (image_candidates($q) as $c) {
        if (isset($usedImages[$c['id']])) continue;
        foreach ([$c['thumb'], $c['url']] as $src) {
            if (!$src) continue;
            [$code, $bin] = http_get($src);
            if ($code !== 200 || strlen($bin) < 4000) continue;
            $info = @getimagesizefromstring($bin);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
            if (!$ext || $info[0] < 200) continue;
            $hash = md5($bin);
            if (isset($usedImages['h' . $hash])) continue; // same picture under another id
            $dir = APP_ROOT . '/uploads/' . $folder;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $name = 'seed-' . substr($hash, 0, 16) . '.' . $ext;
            file_put_contents($dir . '/' . $name, $bin);
            $usedImages[$c['id']] = true;
            $usedImages['h' . $hash] = true;
            return 'uploads/' . $folder . '/' . $name;
        }
    }
    return null;
}

/* fresh */
function remove_seed_data(): void
{
    $ids = array_map('intval', array_column(db_all("SELECT id FROM users WHERE email LIKE ?", ['%@' . SEED_DOMAIN]), 'id'));
    if (!$ids) return;
    $in = implode(',', $ids);
    $vids = array_map('intval', array_column(db_all("SELECT id FROM vendors WHERE user_id IN ($in)"), 'id')) ?: [0];
    $vin = implode(',', $vids);
    foreach (db_all("SELECT image FROM products WHERE vendor_id IN ($vin) UNION SELECT image FROM vendors WHERE id IN ($vin)") as $r) delete_upload($r['image']);
    db()->exec("DELETE FROM reviews WHERE customer_id IN ($in) OR vendor_id IN ($vin)");
    db()->exec("DELETE FROM conversations WHERE customer_id IN ($in) OR vendor_id IN ($vin)");
    db()->exec("DELETE FROM orders WHERE customer_id IN ($in) OR vendor_id IN ($vin)");
    db()->exec("DELETE FROM products WHERE vendor_id IN ($vin)");
    db()->exec("DELETE FROM users WHERE id IN ($in)");
    foreach (db_all("SELECT id, image FROM markets WHERE slug LIKE 'seed-%'") as $m) { delete_upload($m['image']); }
    db()->exec("DELETE FROM markets WHERE slug LIKE 'seed-%'");
    echo "Removed previous seed data.\n";
}

/* run */
if (in_array('--fresh', $argv, true)) {
    remove_seed_data();
}
if (db_value('SELECT 1 FROM users WHERE email LIKE ? LIMIT 1', ['%@' . SEED_DOMAIN])) {
    exit("Seed data already exists. Run with --fresh to rebuild it.\n");
}
foreach (db_all("SELECT image FROM products WHERE image IS NOT NULL UNION SELECT image FROM vendors WHERE image IS NOT NULL") as $r) {
    if (is_file(APP_ROOT . '/' . $r['image'])) $usedImages['h' . md5_file(APP_ROOT . '/' . $r['image'])] = true;
}

$hash = password_hash(SEED_PASSWORD, PASSWORD_DEFAULT);
$adminId = (int) db_value("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1") ?: null;
$slugBase = fn(string $s) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', $s), '.'));
$allVendors = [];
$productCount = 0;

foreach ($CATALOGUE as $typeId => $cat) {
    $type = db_one('SELECT * FROM market_types WHERE id = ?', [$typeId]);
    if (!$type) { echo "Skipping missing market type $typeId\n"; continue; }
    echo "== {$type['name']}\n";
    $categoryId = (int) db_value('SELECT id FROM categories WHERE market_type_id = ? AND parent_id IS NULL ORDER BY sort_order, id LIMIT 1', [$typeId]);

    // category image (only if the admin hasn't set one)
    if (!$type['image'] && ($img = seed_image($cat['q'], 'market-categories'))) {
        db_query('UPDATE market_types SET image = ? WHERE id = ?', [$img, $typeId]);
    }

    // market location for this category
    [$mName, $mCity, $mArea, $mLat, $mLng] = $cat['market'];
    $marketId = (int) db_value('SELECT id FROM markets WHERE name = ? AND city = ?', [$mName, $mCity]);
    if (!$marketId) {
        db_query("INSERT INTO markets (market_type_id, name, slug, description, image, city, area, address, latitude, longitude, opening_time, closing_time, operating_days, status, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '07:00', '21:00', 'mon,tue,wed,thu,fri,sat,sun', 'active', ?)",
            [$typeId, $mName, 'seed-' . slugify($mName . '-' . $mCity), 'The ' . $type['name'] . ' of ' . $mCity . ' - ' . lcfirst((string) $type['description']),
             seed_image($mName . ' ' . $type['name'], 'markets') ?? seed_image($type['name'] . ' pakistan', 'markets'), $mCity, $mArea, $mArea . ', ' . $mCity, $mLat, $mLng, $adminId]);
        $marketId = (int) db()->lastInsertId();
    }

    $products = $cat['products'];
    foreach ($VENDORS[$typeId] as $i => [$stall, $owner, $city]) {
        $email = $slugBase($stall) . '@' . SEED_DOMAIN;
        $phone = '+92 3' . mt_rand(0, 4) . mt_rand(0, 9) . ' ' . mt_rand(1000000, 9999999);
        db_query("INSERT INTO users (name, email, password_hash, role, phone, approval_status, created_at) VALUES (?, ?, ?, 'vendor', ?, 'approved', NOW() - INTERVAL ? DAY)",
            [$owner, $email, $hash, $phone, mt_rand(20, 200)]);
        $userId = (int) db()->lastInsertId();
        [$lat, $lng] = $CITY_COORDS[$city] ?? [$mLat, $mLng];
        $lat += mt_rand(-300, 300) / 10000; $lng += mt_rand(-300, 300) / 10000;
        $days = ['mon,tue,wed,thu,fri,sat', 'mon,wed,fri,sat,sun', 'tue,thu,sat,sun', 'mon,tue,wed,thu,fri,sat,sun'][mt_rand(0, 3)];
        $start = ['07:00', '08:00', '09:00', '10:00'][mt_rand(0, 3)];
        $end = ['18:00', '19:00', '20:00', '21:00'][mt_rand(0, 3)];
        $desc = $owner . ' runs ' . $stall . ' from ' . $city . '. ' . [
            'Family business for three generations - straight from our own land to your kitchen.',
            'We deal only in fresh, carefully graded stock and fair prices.',
            'Trusted by local families and restaurants across ' . $city . '.',
        ][$i % 3];
        $stallImage = seed_image($type['name'] . ' stall ' . ['farmer', 'seller', 'shop'][$i % 3], 'vendors')
            ?? seed_image(explode(' ', $type['name'])[0] . ' farmer', 'vendors');
        db_query("INSERT INTO vendors (user_id, stall_name, contact_person, phone, email, address, description, operating_days, pickup_start, pickup_end, image, latitude, longitude, location_address, is_active)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [$userId, $stall, $owner, $phone, $email, 'Shop ' . mt_rand(3, 99) . ', ' . $mArea . ', ' . $city, $desc, $days, $start, $end, $stallImage, $lat, $lng, $city . ', Pakistan']);
        $vendorId = (int) db()->lastInsertId();
        db_query('INSERT IGNORE INTO farmer_categories (vendor_id, market_type_id) VALUES (?, ?)', [$vendorId, $typeId]);
        db_query("INSERT IGNORE INTO farmer_markets (vendor_id, market_id, status, assigned_by) VALUES (?, ?, 'active', ?)", [$vendorId, $marketId, $adminId]);

        // 7 products from this category only: rotate so the three stalls stock different mixes
        $pick = [];
        for ($k = 0; $k < 7; $k++) $pick[] = $products[($i * 4 + $k) % count($products)];
        foreach ($pick as $prod) {
            [$name, $unit, $price, $stock, $q] = $prod;
            $catOverride = $prod[5] ?? null;
            $p = round($price * (0.9 + mt_rand(0, 25) / 100) / 5) * 5;
            $qty = $stock <= 10 ? mt_rand(1, $stock) : round($stock * mt_rand(40, 110) / 100);
            $img = seed_image($q, 'products');
            if (!$img) echo "   no image for $name\n";
            db_query("INSERT INTO products (vendor_id, market_id, category_id, name, description, price, unit, quantity, image, status, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', NOW() - INTERVAL ? DAY)",
                [$vendorId, $marketId, $catOverride ?? $categoryId ?: null, $name,
                 $name . ' from ' . $stall . ', ' . $city . '. ' . ['Freshly harvested this week.', 'Carefully sorted, top grade.', 'Direct from the farm - no middlemen.', 'Packed on the day of pickup.'][mt_rand(0, 3)],
                 $p, $unit, $qty, $img, mt_rand(1, 60)]);
            $productCount++;
        }
        $allVendors[] = $vendorId;
        echo "   + $stall ($city)\n";
    }
}

/* customers, orders, reviews */
echo "== Customers, orders and reviews\n";
$customerIds = [];
foreach ($CUSTOMERS as $n) {
    db_query("INSERT INTO users (name, email, password_hash, role, phone, approval_status, created_at) VALUES (?, ?, ?, 'customer', ?, 'approved', NOW() - INTERVAL ? DAY)",
        [$n, $slugBase($n) . '@' . SEED_DOMAIN, $hash, '+92 3' . mt_rand(0, 4) . mt_rand(0, 9) . ' ' . mt_rand(1000000, 9999999), mt_rand(10, 150)]);
    $customerIds[] = (int) db()->lastInsertId();
}
$orders = 0; $reviews = 0;
foreach ($allVendors as $vid) {
    $vp = db_all('SELECT id, name, unit, price FROM products WHERE vendor_id = ?', [$vid]);
    $buyers = (array) array_rand(array_flip($customerIds), mt_rand(2, 4));
    foreach ($buyers as $cid) {
        $status = mt_rand(1, 10) <= 8 ? 'completed' : ['pending', 'accepted', 'ready'][mt_rand(0, 2)];
        $daysAgo = $status === 'completed' ? mt_rand(3, 60) : mt_rand(0, 2);
        $lines = [];
        foreach ((array) array_rand($vp, min(count($vp), mt_rand(1, 3))) as $idx) {
            $pr = $vp[$idx];
            $q = $pr['price'] > 20000 ? 1 : ($pr['unit'] === 'g' ? mt_rand(1, 5) * 50 : mt_rand(1, 5));
            $lines[] = [$pr, $q, round($q * (float) $pr['price'], 2)];
        }
        $total = array_sum(array_column($lines, 2));
        $created = date('Y-m-d H:i:s', strtotime("-$daysAgo days -" . mt_rand(0, 600) . ' minutes'));
        $pickup = $status === 'pending' ? null : date('Y-m-d', strtotime($created . ' +1 day'));
        db_query('INSERT INTO orders (customer_id, vendor_id, pickup_date, pickup_start, pickup_end, total_amount, status, status_changed_at, created_at, picked_up_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$cid, $vid, $pickup, $pickup ? '17:00' : null, $pickup ? '17:30' : null, $total, $status, $created, $created, $status === 'completed' ? $created : null]);
        $oid = (int) db()->lastInsertId();
        db_query('UPDATE orders SET order_number = ? WHERE id = ?', [order_number($oid), $oid]);
        foreach ($lines as [$pr, $q, $sub]) {
            db_query('INSERT INTO order_items (order_id, product_id, product_name, unit, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$oid, $pr['id'], $pr['name'], $pr['unit'], $q, $pr['price'], $sub]);
        }
        $flow = ['pending', 'accepted', 'preparing', 'ready', 'picked_up', 'completed'];
        foreach (array_slice($flow, 0, array_search($status, $flow, true) + 1) as $j => $st) {
            db_query('INSERT INTO order_status_log (order_id, from_status, to_status, changed_by, created_at) VALUES (?, ?, ?, ?, ?)',
                [$oid, $j ? $flow[$j - 1] : null, $st, $cid, date('Y-m-d H:i:s', strtotime($created . " +{$j} hours"))]);
        }
        $orders++;
        if ($status === 'completed' && mt_rand(1, 10) <= 8) {
            $rating = [5, 5, 5, 4, 4, 3][mt_rand(0, 5)];
            $text = $REVIEWS[$rating][array_rand($REVIEWS[$rating])];
            $reply = mt_rand(0, 1) ? 'JazakAllah! Aap ka shukriya - dobara zaroor tashreef layein.' : null;
            db_query('INSERT INTO reviews (customer_id, vendor_id, order_id, rating, review, vendor_reply, created_at, replied_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$cid, $vid, $oid, $rating, $text, $reply, date('Y-m-d H:i:s', strtotime($created . ' +1 day')), $reply ? date('Y-m-d H:i:s', strtotime($created . ' +2 days')) : null]);
            $reviews++;
        }
    }
}

echo "\nDone: " . count($CATALOGUE) . ' markets, ' . count($allVendors) . " vendors, $productCount products, " . count($customerIds) . " customers, $orders orders, $reviews reviews.\n";
echo 'Seed logins: any vendor/customer email @' . SEED_DOMAIN . ' with password ' . SEED_PASSWORD . "\n";
