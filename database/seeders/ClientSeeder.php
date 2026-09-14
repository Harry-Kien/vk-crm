<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\ClientUser;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * 12 khách cố định. Khách 1 trùng với DemoAccountsSeeder (khach1@example.com).
     * Khách 2, 5, 8, 11 có hai tài khoản portal.
     *
     * @return list<array{name: string, type: ClientType, id_number: string, phone: string, address: string, representative?: string}>
     */
    public static function clients(): array
    {
        return [
            ['name' => 'Nguyễn Văn An', 'type' => ClientType::Individual, 'id_number' => '079090001234', 'phone' => '0901234567', 'address' => 'Quận 1, TP. Hồ Chí Minh'],
            ['name' => 'Trần Thị Bình', 'type' => ClientType::Individual, 'id_number' => '079185002345', 'phone' => '0902345678', 'address' => 'Quận 3, TP. Hồ Chí Minh'],
            ['name' => 'Lê Hoàng Cường', 'type' => ClientType::Individual, 'id_number' => '001088003456', 'phone' => '0903456789', 'address' => 'Quận Hà Đông, Hà Nội'],
            ['name' => 'Công ty TNHH Xây dựng Đại Phát', 'type' => ClientType::Organization, 'id_number' => '0312345678', 'phone' => '02838123456', 'address' => 'Quận Bình Thạnh, TP. Hồ Chí Minh', 'representative' => 'Phạm Đại Phát'],
            ['name' => 'Phạm Thị Dung', 'type' => ClientType::Individual, 'id_number' => '079192004567', 'phone' => '0905678901', 'address' => 'TP. Thủ Đức, TP. Hồ Chí Minh'],
            ['name' => 'Hoàng Minh Đức', 'type' => ClientType::Individual, 'id_number' => '048095005678', 'phone' => '0906789012', 'address' => 'Quận Hải Châu, Đà Nẵng'],
            ['name' => 'Vũ Thị Em', 'type' => ClientType::Individual, 'id_number' => '079078006789', 'phone' => '0907890123', 'address' => 'Quận 7, TP. Hồ Chí Minh'],
            ['name' => 'Công ty Cổ phần Thương mại Sao Việt', 'type' => ClientType::Organization, 'id_number' => '0109876543', 'phone' => '02439876543', 'address' => 'Quận Cầu Giấy, Hà Nội', 'representative' => 'Đặng Sao Việt'],
            ['name' => 'Đặng Văn Giang', 'type' => ClientType::Individual, 'id_number' => '079083007890', 'phone' => '0908901234', 'address' => 'Quận Gò Vấp, TP. Hồ Chí Minh'],
            ['name' => 'Bùi Thị Hạnh', 'type' => ClientType::Individual, 'id_number' => '075091008901', 'phone' => '0909012345', 'address' => 'TP. Biên Hoà, Đồng Nai'],
            ['name' => 'Hộ kinh doanh Minh Khang', 'type' => ClientType::Organization, 'id_number' => '8123456789', 'phone' => '0910123456', 'address' => 'Quận Tân Bình, TP. Hồ Chí Minh', 'representative' => 'Lý Minh Khang'],
            ['name' => 'Ngô Thanh Kiên', 'type' => ClientType::Individual, 'id_number' => '079096009012', 'phone' => '0911234567', 'address' => 'Quận 10, TP. Hồ Chí Minh'],
        ];
    }

    public function run(): void
    {
        foreach (self::clients() as $index => $data) {
            $n = $index + 1;
            $email = "khach{$n}@example.com";

            $client = Client::query()->firstOrCreate(
                ['email' => $email],
                [
                    'type' => $data['type'],
                    'name' => $data['name'],
                    'id_number' => $data['id_number'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'representative_name' => $data['representative'] ?? null,
                ],
            );

            $this->portalUser($client, $email, $data['representative'] ?? $data['name']);

            if (in_array($n, [2, 5, 8, 11], true)) {
                $this->portalUser($client, "khach{$n}b@example.com", 'Người thân của '.$data['name']);
            }
        }
    }

    private function portalUser(Client $client, string $email, string $name): void
    {
        ClientUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'client_id' => $client->id,
                'name' => $name,
                'password' => 'password',
                'is_active' => true,
                'must_change_password' => false,
                'activated_at' => now(),
            ],
        );
    }
}
