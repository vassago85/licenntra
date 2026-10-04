<?php

namespace Database\Seeders;

use App\Models\DocumentRule;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentRuleSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'srf', 'name' => 'Sales registration form', 'is_identity_document' => false, 'max_age_days' => null],
            ['code' => 'weighbridge_certificate', 'name' => 'Weighbridge certificate', 'is_identity_document' => false, 'max_age_days' => null],
            ['code' => 'cof', 'name' => 'Certificate of fitness', 'is_identity_document' => false, 'max_age_days' => null],
            ['code' => 'body_builder_certificate', 'name' => 'Body builder certificate', 'is_identity_document' => false, 'max_age_days' => null],
            ['code' => 'brn_certificate', 'name' => 'BRN certificate', 'is_identity_document' => false, 'max_age_days' => null],
            ['code' => 'proxy_id', 'name' => 'Proxy ID copy', 'is_identity_document' => true, 'max_age_days' => null],
            // ASSUMPTION: a proof of address older than 90 days is stale.
            ['code' => 'poa', 'name' => 'Proof of address', 'is_identity_document' => false, 'max_age_days' => 90],
            ['code' => 'id_copy', 'name' => 'ID copy', 'is_identity_document' => true, 'max_age_days' => null],
            ['code' => 'title_holder_brn', 'name' => 'BRN certificate', 'is_identity_document' => false, 'max_age_days' => null],
            ['code' => 'title_holder_proxy_id', 'name' => 'Proxy ID copy', 'is_identity_document' => true, 'max_age_days' => null],
            ['code' => 'supporting_documents', 'name' => 'Supporting documents', 'is_identity_document' => false, 'max_age_days' => null],
        ];

        $ids = [];

        foreach ($types as $type) {
            $ids[$type['code']] = DocumentType::query()->updateOrCreate(['code' => $type['code']], $type)->id;
        }

        $rules = [
            ['srf', 'new_registration', null, null, 'vehicle', 'required', 10],
            ['srf', 'data_change', null, null, 'vehicle', 'optional', 10],
            ['weighbridge_certificate', 'new_registration', 'commercial', null, 'vehicle', 'required', 20],
            ['weighbridge_certificate', 'data_change', 'commercial', null, 'vehicle', 'required', 20],
            ['cof', 'new_registration', 'commercial', null, 'vehicle', 'required', 30],
            ['cof', 'data_change', 'commercial', null, 'vehicle', 'required', 30],
            // A commercial licence renewal requires a current certificate of
            // fitness — that is the whole reason discs get withheld when the
            // roadworthy is outstanding.
            ['cof', 'licence_renewal', 'commercial', null, 'vehicle', 'required', 30],
            ['body_builder_certificate', 'new_registration', 'commercial', null, 'vehicle', 'required', 40],
            ['body_builder_certificate', 'data_change', 'commercial', null, 'vehicle', 'required', 40],
            ['brn_certificate', 'new_registration', null, 'business', 'owner', 'required', 50],
            ['brn_certificate', 'data_change', null, 'business', 'owner', 'required', 50],
            ['proxy_id', 'new_registration', null, 'business', 'owner', 'required', 60],
            ['proxy_id', 'data_change', null, 'business', 'owner', 'required', 60],
            ['poa', 'new_registration', null, null, 'owner', 'required', 70],
            ['poa', 'data_change', null, null, 'owner', 'required', 70],
            ['id_copy', 'new_registration', null, 'individual', 'owner', 'required', 80],
            ['id_copy', 'data_change', null, 'individual', 'owner', 'required', 80],
            ['title_holder_brn', 'new_registration', null, null, 'title_holder', 'required', 90, true],
            ['title_holder_brn', 'data_change', null, null, 'title_holder', 'required', 90, true],
            ['title_holder_proxy_id', 'new_registration', null, null, 'title_holder', 'required', 100, true],
            ['title_holder_proxy_id', 'data_change', null, null, 'title_holder', 'required', 100, true],
            ['supporting_documents', 'import', null, null, 'vehicle', 'optional', 200],
            ['supporting_documents', 'export', null, null, 'vehicle', 'optional', 200],
        ];

        foreach ($rules as $index => $rule) {
            DocumentRule::query()->updateOrCreate(
                [
                    'document_type_id' => $ids[$rule[0]],
                    'request_type' => $rule[1],
                    'vehicle_category' => $rule[2],
                    'owner_type' => $rule[3],
                    'party_role' => $rule[4],
                    'is_financed' => $rule[7] ?? null,
                ],
                [
                    'requirement' => $rule[5],
                    'sort_order' => $rule[6],
                    'active' => true,
                ],
            );
        }
    }
}
