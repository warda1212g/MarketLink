<?php
/** POST (multipart) -> update vendor business profile, market info and stall image. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';

api_run(function () {
    require_method('POST');
    $vendor = require_vendor_api();
    $in = request_data();

    $data = [
        'stall_name'     => str_input($in, 'stall_name', 150),
        'contact_person' => str_input($in, 'contact_person', 120),
        'phone'          => str_input($in, 'phone', 30),
        'email'          => mb_strtolower(str_input($in, 'email', 190)),
        'address'        => str_input($in, 'address', 255),
        'description'    => str_input($in, 'description', 2000),
        'pickup_start'   => str_input($in, 'pickup_start', 8),
        'pickup_end'     => str_input($in, 'pickup_end', 8),
    ];
    $days = array_values(array_intersect((array) ($in['operating_days'] ?? []), DAY_KEYS));

    $e = [];
    if (mb_strlen($data['stall_name']) < 2) $e['stall_name'] = 'Enter your stall or business name.';
    if (mb_strlen($data['contact_person']) < 2) $e['contact_person'] = 'Enter a contact person.';
    if (!preg_match('/^[0-9+\-\s()]{7,30}$/', $data['phone'])) $e['phone'] = 'Enter a valid phone number.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Enter a valid email address.';
    if (mb_strlen($data['address']) < 5) $e['address'] = 'Enter your stall address.';
    if (mb_strlen($data['description']) > 2000) $e['description'] = 'Keep the description under 2000 characters.';
    if (!$days) $e['operating_days'] = 'Choose at least one operating day.';
    $categoryIds = array_map('intval', (array) ($in['category_ids'] ?? []));
    if (!$categoryIds || !db_value('SELECT 1 FROM market_types WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . ')', $categoryIds)) {
        $e['category_ids'] = 'Choose the market category your stall belongs to.';
    }
    if (!valid_time($data['pickup_start'])) $e['pickup_start'] = 'Enter a pickup start time.';
    if (!valid_time($data['pickup_end'])) $e['pickup_end'] = 'Enter a pickup end time.';
    if (!isset($e['pickup_start'], $e['pickup_end']) && valid_time($data['pickup_start']) && valid_time($data['pickup_end'])
        && strtotime($data['pickup_end']) <= strtotime($data['pickup_start'])) {
        $e['pickup_end'] = 'Pickup must end after it starts.';
    }
    if ($e) throw new ValidationException($e);

    $image = store_uploaded_image('image', 'vendors');

    db_query('UPDATE vendors SET stall_name=?, contact_person=?, phone=?, email=?, address=?, description=?, operating_days=?,
                     pickup_start=?, pickup_end=?, image=COALESCE(?, image) WHERE id = ?', [
        $data['stall_name'], $data['contact_person'], $data['phone'], $data['email'], $data['address'],
        $data['description'] ?: null, implode(',', $days), $data['pickup_start'], $data['pickup_end'], $image, $vendor['id'],
    ]);
    if ($image) delete_upload($vendor['image']);
    vendor_set_categories((int) $vendor['id'], $categoryIds);

    json_ok(['image' => $image ? upload_url($image) : upload_url($vendor['image'])], 'Profile saved successfully.');
});
