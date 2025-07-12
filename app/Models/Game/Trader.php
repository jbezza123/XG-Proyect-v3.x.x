<?php

namespace App\Models\Game;

use App\Core\Model;

class Trader extends Model
{
    public function refillStorage(int $dark_matter, string $resource, float $amount, int $user_id, int $planet_id): void
{
    // First: validate that $resource is safe
    $valid_resources = ['metal', 'crystal', 'deuterium'];
    if (!in_array($resource, $valid_resources)) {
        return; // or throw an exception
    }

    // Fix: Use += for resource, and -= for DM
    $this->db->query(
        'UPDATE `' . PREMIUM . '` pr, `' . PLANETS . "` p 
        SET pr.`premium_dark_matter` = pr.`premium_dark_matter` - " . (int)$dark_matter . ",
            p.`planet_" . $resource . "` = p.`planet_" . $resource . "` + " . (float)$amount . "
        WHERE pr.`premium_user_id` = " . (int)$user_id . "
            AND p.`planet_id` = " . (int)$planet_id . ";"
    );
}

}
