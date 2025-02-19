<?php

namespace App\Http\Controllers\api\app\ngo;

use App\Models\Ngo;
use App\Models\Email;
use App\Models\Address;
use App\Models\NgoTran;
use App\Enums\LanguageEnum;
use App\Enums\StatusTypeEnum;
use App\Http\Requests\app\ngo\NgoProfileUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\app\ngo\NgoRegisterRequest;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\AddressTran;

class NgoController extends Controller
{


    public function ngos(Request $request, $page)
    {
        $locale = App::getLocale();
        $perPage = $request->input('per_page', 10); // Number of records per page
        $page = $request->input('page', 1); // Current page

        // Eager loading relationships
   $query = Ngo::with([
    'ngoTrans:id,ngo_id,name', // Selects only id, ngo_id, and name for translations
    'ngoType' => function ($query) use ($locale) {
        $query->with(['ngoTypeTrans' => function ($query) use ($locale) {
            $query->where('language_name', $locale)
                  ->select('ngo_type_id', 'value as name'); // Select only required fields
        }])->select('id'); // Select only required fields for ngoType
    },
    'ngoStatus' => function ($query) use ($locale) {
        $query->with(['ngoStatusType' => function ($query) use ($locale) {
            $query->with(['statusTypeTran' => function ($query) use ($locale) {
                $query->where('language_name', $locale)
                      ->select('id', 'status_type_id', 'name'); // Filter by locale and select fields
            }]);
        }]);
    },
    'agreement' => function ($query) {
        $query->select('ngo_id', 'end_date'); // Fetch only the required fields
    },
])
->select([
    'id',
    'registration_no',
    'establishment_date',
    'ngo_type_id', // Fetch only necessary fields for Ngo
]);


        // Apply filters
        $this->applyDateFilters($query, $request->input('filters.date.startDate'), $request->input('filters.date.endDate'));
        $this->applySearchFilter($query, $request->input('filters.search'));

        // Apply sorting
        $sort = $request->input('filters.sort', 'registration_no');
        $order = $request->input('filters.order', 'asc');
        $query->orderBy($sort, $order);

        // Paginate results
        $result = $query->paginate($perPage, ['*'], 'page', $page);

        // Return JSON response
        return response()->json(
            ["ngos" => $result],
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }

    private function applySearchFilter($query, $search)
    {
        if (!empty($search['column']) && !empty($search['value'])) {
            $allowedColumns = ['registration_no', 'id', 'ngoType.name', 'ngoTran.name'];

            if (in_array($search['column'], $allowedColumns)) {
                if ($search['column'] == 'ngoType.name') {
                    // Search in ngoType's name (aliased as type_name)
                    $query->whereHas('ngoType', function ($q) use ($search) {
                        $q->where('name', 'like', '%' . $search['value'] . '%');
                    });
                } elseif ($search['column'] == 'ngoTran.name') {
                    // Search in ngoTran's name (aliased as ngo_name)
                    $query->whereHas('ngoTran', function ($q) use ($search) {
                        $q->where('name', 'like', '%' . $search['value'] . '%');
                    });
                } else {
                    // Default search for registration_no or id
                    $query->where($search['column'], 'like', '%' . $search['value'] . '%');
                }
            }
        }
    }

    private function applyDateFilters($query, $startDate, $endDate)
    {
        if ($startDate || $endDate) {
            if ($startDate && $endDate) {
                $query->whereBetween('ngos.establishment_date', [$startDate, $endDate]);
            } elseif ($startDate) {
                $query->where('ngos.establishment_date', '>=', $startDate);
            } elseif ($endDate) {
                $query->where('ngos.establishment_date', '<=', $endDate);
            }
        }
    }

    public function store(NgoRegisterRequest $request)
    {
        $validatedData = $request->validated();
        // Begin transaction
        DB::beginTransaction();
        // Create email
        $email = Email::create(['value' => $validatedData['email']]);
        $contact = Contact::create(['value' => $validatedData['contact']]);
        // Create address
        $address = Address::create([
            'district_id' => $validatedData['district_id'],
        ]);
        AddressTran::create([
            'address_id' => $address->id,
            'area' => $validatedData['area'],
            'language_name' =>  LanguageEnum::default->value,
        ]);
        // Create NGO
        $newNgo = Ngo::create([
            'abbr' => $validatedData['abbr'],
            'registration_no' => "",
            'ngo_type_id' => $validatedData['ngo_type_id'],
            'address_id' => $address->id,
            'email_id' => $email->id,
            'contact_id' => $contact->id,
            "password" => Hash::make($validatedData['password']),
        ]);

        // Crea a registration_no
        $newNgo->registration_no = "IRD" . '-' . Carbon::now()->year . '-' . $newNgo->id;
        $newNgo->save();
        NgoTran::create([
            'ngo_id' => $newNgo->id,
            'language_name' =>  LanguageEnum::default->value,
            'name' => $validatedData['name_en'],
        ]);
        NgoTran::create([
            'ngo_id' => $newNgo->id,
            'language_name' =>  LanguageEnum::farsi->value,
            'name' => $validatedData['name_fa'],
        ]);
        NgoTran::create([
            'ngo_id' => $newNgo->id,
            'language_name' =>  LanguageEnum::pashto->value,
            'name' => $validatedData['name_ps'],
        ]);

        $locale = App::getLocale();
        $name =  $validatedData['name_en'];
        if ($locale == LanguageEnum::farsi->value) {
            $name = $validatedData['name_fa'];
        } else if ($locale == LanguageEnum::pashto->value) {
            $name = $validatedData['name_ps'];
        }
        // If everything goes well, commit the transaction
        DB::commit();
        return response()->json(
            [
                'message' => __('app_translation.success'),
                "ngo" => [
                    "id" => $newNgo->id,
                    "profile" => $newNgo->profile,
                    "registrationNo" => $newNgo->registration_no,
                    "name" => $name,
                    "contact" => $contact,
                ]
            ],
            200,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    public function profileUpdate(NgoProfileUpdateRequest $request, $id)
    {


        // Find the NGO
        $ngo = Ngo::find($id);

        if (!$ngo || $ngo->is_editable != 1) {
            return response()->json(['message' => __('app_translation.notEditable')], 403);
        }


        $validatedData = $request->validated();

        try {
            // Begin transaction
            DB::beginTransaction();

            $path = $this->storeProfile($request, 'ngo-profile');
            $ngo->update([
                "profile" =>  $path,
            ]);
            // Update default language record
            $ngoTran = NgoTran::where('ngo_id', $id)
                ->where('language_name', LanguageEnum::default->value)
                ->first();

            if ($ngoTran) {
                $ngoTran->update([
                    'name' => $validatedData['name_en'],
                    'vision' => $validatedData['vision_en'],
                    'mission' => $validatedData['mission_en'],
                    'general_objective' => $validatedData['general_objective_en'],
                    'objective' => $validatedData['objective_en'],
                    'introduction' => $validatedData['introduction_en']
                ]);
            } else {
                return response()->json(['message' => __('app_translation.not_found')], 404);
            }

            // Manage multilingual NgoTran records
            $languages = [
                'ps',
                'fa'

            ];

            foreach ($languages as   $suffix) {
                NgoTran::updateOrCreate(
                    ['ngo_id' => $id, 'language_name' => $suffix],
                    [
                        'name' => $validatedData["name_{$suffix}"],
                        'vision' => $validatedData["vision_{$suffix}"],
                        'mission' => $validatedData["mission_{$suffix}"],
                        'general_objective' => $validatedData["general_objective_{$suffix}"],
                        'objective' => $validatedData["objective_{$suffix}"],
                        'introduction' => $validatedData["introduction_{$suffix}"]
                    ]
                );
            }

            // Instantiate DirectorController and call its store method
            $directorController = new \App\Http\Controllers\api\app\director\DirectorController();
            $directorController->store($request, $id);

            // store document
            // Commit transaction
            DB::commit();
            return response()->json(['message' => __('app_translation.success')], 200);
        } catch (\Exception $e) {
            // Rollback on error
            DB::rollBack();
            return response()->json(['message' => __('app_translation.server_error') . $e->getMessage()], 500);
        }
    }
    public function ngoCount()
    {
        $statistics = DB::select("
        SELECT
         COUNT(*) AS count,
            (SELECT COUNT(*) FROM ngos WHERE DATE(created_at) = CURDATE()) AS todayCount,
            (SELECT COUNT(*) FROM ngos n JOIN ngo_statuses ns ON n.id = ns.ngo_id WHERE ns.status_type_id = ?) AS activeCount,
         (SELECT COUNT(*) FROM ngos n JOIN ngo_statuses ns ON n.id = ns.ngo_id WHERE ns.status_type_id = ?) AS unRegisteredCount
        FROM ngos
    ", [StatusTypeEnum::active->value, StatusTypeEnum::unregistered->value]);
        return response()->json([
            'counts' => [
                "count" => $statistics[0]->count,
                "todayCount" => $statistics[0]->todayCount,
                "activeCount" => $statistics[0]->activeCount,
                "unRegisteredCount" =>  $statistics[0]->unRegisteredCount
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
