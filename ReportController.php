<?php

namespace App\Http\Controllers\api;

use App\Models\Item;
use App\Models\Task;
use App\Models\User;
use App\Models\Archive;
use App\Models\Meeting;
use App\Models\Category;
use App\Models\TaskType;
use App\Models\UserType;
use App\Models\farStatus;
use App\Models\CostCentre;
use App\Models\ReasonType;
use App\Models\BudgetSpent;
use App\Models\ItemRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\PurchaseRequest;
use App\Models\CostCentreBudget;
use App\Models\ApplicationModule;
use App\Models\ItemRequestStatus;
use App\Models\MeetingReshcedule;
use Illuminate\Support\Facades\DB;
use App\Models\DirectPurchaseOrder;
use App\Models\MeetingParticipants;
use App\Models\PurchaseRequestItem;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\PurchaseRequestStatus;
use App\Models\FramingAgreementRequest;
use App\Models\PurchaseRequestCategory;
use App\Models\DirectPurchaseOrderStatus;

class ReportController extends Controller
{

    public function costCentres(Request $request){
        // director admin can see all the cost centres, cost centre admin or normal user can see the related cost centre only.
        $orgId = Auth::user()->organization_id;
        $userType =  Auth::user()->user_type_id;
        $authUser =  Auth::user();
        
        $query = CostCentre::select('id','en_name','ar_name','code')->where('organization_id', $orgId);
        
        if($userType != UserType::IS_OWNER ){ // for normal users and admin 
            $costcentres = $query->where('id',$authUser->cost_centre_id)->latest()->get();
        }else{ // for director all cost centres will be shown
            $costcentres = $query->latest()->get();
        }
        $costCentreStats = $costcentres->count();

        $data = [
            'number_of_cost_centres' => $costCentreStats,
            'cost_centres' => $costcentres,
        ];
        $response = ['status' => true ,'code' =>200 ,'message'=> __('messages.success'), 'data' => $data];
        return response($response);
    }
    
    // Users List
    public function usersList(Request $request){
        try
        {
            // director admin can see all the users, cost centre admin or normal user can see the related cost centre users only.
            $userType = Auth::user()->user_type_id;

            $usersList = User::where([['organization_id', Auth::user()->organization_id], ['user_type_id', '!=', UserType::IS_SUPPLIER], ['status', 1], ['is_active', 1]]);

            if($userType == UserType::IS_DEPARTMENT_ADMIN)
            {
                $usersList = $usersList->where([['cost_centre_id', Auth::user()->cost_centre_id], ['user_type_id', '!=', UserType::IS_OWNER]]);
            }
            else if($userType == UserType::IS_USER)
            {
                $usersList = $usersList->where([['cost_centre_id', Auth::user()->cost_centre_id], ['user_type_id', UserType::IS_USER]]);
            }

            $usersList = $usersList->select('id', 'first_name', 'last_name')->orderBy('first_name')->get();

            $response = ['status' => true, 'code' => 200, 'message'=> __('messages.success'), 'data' => $usersList];
            return response($response);
        }
        catch (\Exception $e)
        {
            $response = ['status' => false, 'code' => 500, 'message' => $e->getMessage()];
            return response($response);
        }
    }

    // Cost Center Report
    public function costCenterReport(Request $request)
    {
        try
        {
            $validated = $request->validate([
                'cost_centre_id'   => 'required',
                'data' => 'required|in:stats_data,item_requests_data,purchase_requests_data,dpo_data,far_data,cost_centre_data',
                'from_date'   => 'nullable|date_format:Y-m-d',
                'to_date'   => 'nullable|date_format:Y-m-d',
            ]);

            if (!$validated)
            {
                return response()->json(['status' => false, 'message' => $validator->messages()]);
            }

            if($request->data == 'stats_data')
            {
                // Transactions Stats
                $itemRequestsCount = ItemRequest::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $purchaseRequestsCount = PurchaseRequest::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $directPurchaseOrderCount = DirectPurchaseOrder::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $framingAgreementRequestCount = FramingAgreementRequest::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $totalTransaction = $itemRequestsCount + $purchaseRequestsCount + $directPurchaseOrderCount + $framingAgreementRequestCount;

                // Budget Stats
                $costCentreBudgets = CostCentreBudget::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->where(function($query) use ($request){
                            $query->whereDate('start_date', '>=', $request->from_date)
                            ->whereDate('end_date', '<=', $request->to_date);
                        })
                        ->orWhere(function($query) use ($request) {
                            $query->whereDate('end_date', '>=', $request->from_date)
                            ->whereDate('start_date', '<=', $request->to_date);
                        });
                    });
                })
                ->select('id', 'amount')
                ->get();

                $totalCostCentreBudget = $costCentreBudgets->sum('amount');
                $costCenterBudgetIDs = $costCentreBudgets->pluck('id')->toArray();

                $spentBudget = BudgetSpent::whereIn('cost_centre_budget_id', $costCenterBudgetIDs)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->sum('deducted_amount');

                // If there is spent budget transactions, then calculate rest budget.
                // Else rest budget will be equal to total budget
                if($spentBudget > 0)
                {
                    $restBudget = 0;

                    // To get the rest budget
                    foreach($costCenterBudgetIDs as $budgetID)
                    {
                        $checkRestBudget = BudgetSpent::where('cost_centre_budget_id', $budgetID)
                        ->when(($request->from_date && $request->to_date), function($query) use ($request){
                            $query->where(function($query) use ($request){
                                $query->whereDate('created_at', '>=', $request->from_date)
                                ->whereDate('created_at', '<=', $request->to_date);
                            });
                        })
                        ->first();

                        // If there exists any spent budget transaction against specific a budget, then sum rest budget from budget_spents table.
                        // Else get the rest budget from cost_centre_budgets table.
                        if($checkRestBudget)
                        {   
                            $restBudget += BudgetSpent::where('cost_centre_budget_id', $budgetID)
                            ->when(($request->from_date && $request->to_date), function($query) use ($request){
                                $query->where(function($query) use ($request){
                                    $query->whereDate('created_at', '>=', $request->from_date)
                                    ->whereDate('created_at', '<=', $request->to_date);
                                });
                            })
                            ->sum('rest_budget');
                        }
                        else
                        {
                            $restBudget += CostCentreBudget::where('id', $budgetID)
                            ->when(($request->from_date && $request->to_date), function($query) use ($request){
                                $query->where(function($query) use ($request) {
                                    $query->where(function($query) use ($request){
                                        $query->whereDate('start_date', '>=', $request->from_date)
                                        ->whereDate('end_date', '<=', $request->to_date);
                                    })
                                    ->orWhere(function($query) use ($request) {
                                        $query->whereDate('end_date', '>=', $request->from_date)
                                        ->whereDate('start_date', '<=', $request->to_date);
                                    });
                                });
                            })
                            ->pluck('rest_budget')
                            ->first();
                        }
                    }
                }
                else
                {
                    $restBudget = $totalCostCentreBudget;
                }

                // Inventory Stats
                $assetsCount = Item::where([['cost_centre_id', $request->cost_centre_id], ['category_id', Category::ASSET]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $consumableCount = Item::where([['cost_centre_id', $request->cost_centre_id], ['category_id', Category::CONSUMABLE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $totalInventory = $assetsCount + $consumableCount;
                
                $data = [
                    'transactions' => [
                        'ir_%age' => ($totalTransaction > 0) ? round(($itemRequestsCount/$totalTransaction) * 100, 2) : 0,
                        'pr_%age' => ($totalTransaction > 0) ? round(($purchaseRequestsCount/$totalTransaction) * 100, 2) : 0,
                        'dpo_%age' => ($totalTransaction > 0) ? round(($directPurchaseOrderCount/$totalTransaction) * 100, 2) : 0,
                        'far_%age' => ($totalTransaction > 0) ? round(($framingAgreementRequestCount/$totalTransaction) * 100, 2) : 0,
                    ],
                    'budget' => [
                        'total_budget' => $totalCostCentreBudget,
                        'spent_%age' => ($totalCostCentreBudget > 0) ? ($spentBudget/$totalCostCentreBudget) * 100 : 0,
                        'rest_%age' => ($totalCostCentreBudget > 0) ? ($restBudget/$totalCostCentreBudget) * 100 : 0,
                    ],
                    'inventory' => [
                        'asset_%age' => ($totalInventory > 0) ? round(($assetsCount/$totalInventory) * 100, 2) : 0,
                        'consumable_%age' => ($totalInventory > 0) ? round(($consumableCount/$totalInventory) * 100, 2) : 0,
                    ],
                ];
            }

            if($request->data == 'item_requests_data')
            {
                // Counts
                $itemRequestsCount = ItemRequest::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $operationalItemRequestsCount = ItemRequest::where([['cost_centre_id', $request->cost_centre_id], ['reason_type_id', ReasonType::Operational]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $assetsItemRequestsCount = ItemRequest::where([['cost_centre_id', $request->cost_centre_id], ['category_id', Category::ASSET]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                // Inventory Stats
                $assetsCount = ItemRequest::where([['cost_centre_id', $request->cost_centre_id], ['category_id', Category::ASSET]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $consumableCount = ItemRequest::where([['cost_centre_id', $request->cost_centre_id], ['category_id', Category::CONSUMABLE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $totalItemRequests = $assetsCount + $consumableCount;

                // Get average time
                // All item requests from when they are created to when their status becomes IR_COMPLETED (1)
                $averageTimeIRCompleted = ItemRequest::
                join('item_request_status_histories', 'item_requests.id', '=', 'item_request_status_histories.item_request_id')
                ->where([['item_request_status_histories.item_request_status_id', ItemRequestStatus::IR_COMPLETED], ['cost_centre_id', $request->cost_centre_id]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('item_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('item_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, item_requests.created_at, item_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All item requests from when they are created to when their status becomes SIGNED (2)
                $averageTimeIRSigned = ItemRequest::
                join('item_request_status_histories', 'item_requests.id', '=', 'item_request_status_histories.item_request_id')
                ->where([['item_request_status_histories.item_request_status_id', ItemRequestStatus::SIGNED], ['cost_centre_id', $request->cost_centre_id]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('item_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('item_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, item_requests.created_at, item_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All item requests from when they are created to when their status becomes APPROVED or REJECTED (5 or 6)
                $averageTimeIRDecision = ItemRequest::
                join('item_request_status_histories', 'item_requests.id', '=', 'item_request_status_histories.item_request_id')
                ->where('cost_centre_id', $request->cost_centre_id)
                ->where(function($query){
                    $query->where('item_request_status_histories.item_request_status_id', ItemRequestStatus::APPROVED)
                    ->orWhere('item_request_status_histories.item_request_status_id', ItemRequestStatus::REJECTED);
                })
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('item_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('item_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, item_requests.created_at, item_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All item requests from when they are created to when their status becomes ITEM_DELIVERED (7)
                $averageTimeIRDelivery = ItemRequest::
                join('item_request_status_histories', 'item_requests.id', '=', 'item_request_status_histories.item_request_id')
                ->where([['item_request_status_histories.item_request_status_id', ItemRequestStatus::ITEM_DELIVERED], ['cost_centre_id', $request->cost_centre_id]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('item_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('item_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, item_requests.created_at, item_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                $data = [
                    'stats' => [
                        'total_item_requests' => $itemRequestsCount,
                        'operational_item_requests' => $operationalItemRequestsCount,
                        'assets_item_requests' => $assetsItemRequestsCount,
                        'graph' => [
                            'assets_%age' => ($totalItemRequests > 0) ? round(($assetsCount/$totalItemRequests) * 100, 2) : 0,
                            'consumable_%age' => ($totalItemRequests > 0) ? round(($consumableCount/$totalItemRequests) * 100, 2) : 0,
                            'total' => $totalItemRequests
                        ]
                    ],
                    'average_time' => [
                        'ir_completed' => $averageTimeIRCompleted,
                        'ir_signed' => $averageTimeIRSigned,
                        'ir_decision' => $averageTimeIRDecision,
                        'ir_delivery' => $averageTimeIRDelivery,
                        'average_time_to_complete' => $averageTimeIRDelivery,
                    ]
                ];
            }

            if($request->data == 'purchase_requests_data')
            {
                // Stats
                $purchaseRequestsCount = PurchaseRequest::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $purchaseRequestAssetsCount = PurchaseRequestItem::
                join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['purchase_requests.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::ASSET]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('purchase_request_items.created_at', '>=', $request->from_date)
                        ->whereDate('purchase_request_items.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $purchaseRequestConsumableCount = PurchaseRequestItem::
                join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['purchase_requests.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::CONSUMABLE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('purchase_request_items.created_at', '>=', $request->from_date)
                        ->whereDate('purchase_request_items.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $purchaseRequestServiceCount = PurchaseRequestItem::
                join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['purchase_requests.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::SERVICE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request){
                        $query->whereDate('purchase_request_items.created_at', '>=', $request->from_date)
                        ->whereDate('purchase_request_items.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $totalItems = $purchaseRequestAssetsCount + $purchaseRequestConsumableCount + $purchaseRequestServiceCount;

                // Get average time
                // All purchase requests from when they are created to when their status becomes Complete_PR_items (2)
                $averageTimeCompletePRItems = PurchaseRequest::
                join('purchase_request_status_histories', 'purchase_requests.id', '=', 'purchase_request_status_histories.purchase_request_id')
                ->where([['purchase_request_status_histories.purchase_request_status_id', PurchaseRequestStatus::Complete_PR_items], ['purchase_requests.cost_centre_id', $request->cost_centre_id]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('purchase_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('purchase_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, purchase_requests.created_at, purchase_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All purchase requests from when they are created to when their status becomes Signed (5)
                $averageTimeSigned = PurchaseRequest::
                join('purchase_request_status_histories', 'purchase_requests.id', '=', 'purchase_request_status_histories.purchase_request_id')
                ->where([['purchase_request_status_histories.purchase_request_status_id', PurchaseRequestStatus::Signed], ['purchase_requests.cost_centre_id', $request->cost_centre_id]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('purchase_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('purchase_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, purchase_requests.created_at, purchase_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All purchase requests from when they are created to when their status becomes Proceed_as_directed_purchase_order (11)
                $averageTimeIssuedDPO = PurchaseRequest::
                join('purchase_request_status_histories', 'purchase_requests.id', '=', 'purchase_request_status_histories.purchase_request_id')
                ->where([['purchase_request_status_histories.purchase_request_status_id', PurchaseRequestStatus::Proceed_as_directed_purchase_order], ['purchase_requests.cost_centre_id', $request->cost_centre_id]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('purchase_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('purchase_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, purchase_requests.created_at, purchase_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All purchase requests from when they are created to when their status becomes Complete_and_close (8)
                $averageTimeToComplete = PurchaseRequest::
                join('purchase_request_status_histories', 'purchase_requests.id', '=', 'purchase_request_status_histories.purchase_request_id')
                ->where([['purchase_request_status_histories.purchase_request_status_id', PurchaseRequestStatus::Complete_and_close], ['purchase_requests.cost_centre_id', $request->cost_centre_id]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('purchase_request_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('purchase_request_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, purchase_requests.created_at, purchase_request_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                $data = [
                    'stats' => [
                        'total_pr' => $purchaseRequestsCount,
                        'pr_assets' => $purchaseRequestAssetsCount,
                        'pr_consumable' => $purchaseRequestConsumableCount,
                        'pr_service' => $purchaseRequestServiceCount,
                        'graph' => [
                            'assets_%age' => ($totalItems > 0) ? round(($purchaseRequestAssetsCount/$totalItems)*100, 2) : 0, 
                            'consumable_%age' => ($totalItems > 0) ? round(($purchaseRequestConsumableCount/$totalItems)*100, 2) : 0, 
                            'service_%age' => ($totalItems > 0) ? round(($purchaseRequestServiceCount/$totalItems)*100, 2) : 0, 
                        ]
                    ],
                    'average_time' => [
                        'pr_complete' => $averageTimeCompletePRItems, 
                        'pr_signed' => $averageTimeSigned, 
                        'pr_issue_dpo' => $averageTimeIssuedDPO, 
                        'average_time_to_complete' => $averageTimeToComplete, 
                    ]
                ];
            }

            if($request->data == 'dpo_data')
            {
                // Stats
                $dpoCount = DirectPurchaseOrder::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $dpoAssetsCount = PurchaseRequestItem::
                join('direct_purchase_orders', 'direct_purchase_orders.purchase_request_id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['direct_purchase_orders.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::ASSET]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('direct_purchase_orders.created_at', '>=', $request->from_date)
                        ->whereDate('direct_purchase_orders.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $dpoConsumableCount = PurchaseRequestItem::
                join('direct_purchase_orders', 'direct_purchase_orders.purchase_request_id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['direct_purchase_orders.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::CONSUMABLE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('direct_purchase_orders.created_at', '>=', $request->from_date)
                        ->whereDate('direct_purchase_orders.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $dpoServiceCount = PurchaseRequestItem::
                join('direct_purchase_orders', 'direct_purchase_orders.purchase_request_id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['direct_purchase_orders.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::SERVICE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('direct_purchase_orders.created_at', '>=', $request->from_date)
                        ->whereDate('direct_purchase_orders.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $totalItems = $dpoAssetsCount + $dpoConsumableCount + $dpoServiceCount;

                // Get average time
                // All direct purchase orders from when they are created to when their status becomes DPO_ISSUED (16)
                $averageTimeIssuedDPO = DirectPurchaseOrder::
                join('d_p_o_status_histories', 'd_p_o_status_histories.dpo_id', '=', 'direct_purchase_orders.id')
                ->where([['direct_purchase_orders.cost_centre_id', $request->cost_centre_id], ['d_p_o_status_histories.dpo_status_id', DirectPurchaseOrderStatus::DPO_ISSUED]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('d_p_o_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('d_p_o_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, direct_purchase_orders.created_at, d_p_o_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All direct purchase orders from when they are created to when their status becomes PAYMENT_CYCLE_START (51)
                $averageTimeToComplete = DirectPurchaseOrder::
                join('d_p_o_status_histories', 'd_p_o_status_histories.dpo_id', '=', 'direct_purchase_orders.id')
                ->where([['direct_purchase_orders.cost_centre_id', $request->cost_centre_id], ['d_p_o_status_histories.dpo_status_id', DirectPurchaseOrderStatus::PAYMENT_CYCLE_START]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use($request){
                        $query->whereDate('d_p_o_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('d_p_o_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, direct_purchase_orders.created_at, d_p_o_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                $data = [
                    'stats' => [
                        'total_dpo' => $dpoCount,
                        'dpo_assets' => $dpoAssetsCount,
                        'dpo_consumable' => $dpoConsumableCount,
                        'dpo_service' => $dpoServiceCount,
                        'graph' => [
                            'assets_%age' => ($totalItems > 0) ? round(($dpoAssetsCount/$totalItems)*100, 2) : 0, 
                            'consumable_%age' => ($totalItems > 0) ? round(($dpoConsumableCount/$totalItems)*100, 2) : 0, 
                            'service_%age' => ($totalItems > 0) ? round(($dpoServiceCount/$totalItems)*100, 2) : 0, 
                        ]
                    ],
                    'average_time' => [
                        'dpo_issues' => $averageTimeIssuedDPO,
                        'dpo_completed' => $averageTimeToComplete
                    ]
                ];
            }

            if($request->data == 'far_data')
            {
                // Stats
                $farCount = FramingAgreementRequest::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $farAssetsCount = PurchaseRequestItem::
                join('framing_agreement_requests', 'framing_agreement_requests.purchase_request_id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::ASSET]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('framing_agreement_requests.created_at', '>=', $request->from_date)
                        ->whereDate('framing_agreement_requests.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $farConsumableCount = PurchaseRequestItem::
                join('framing_agreement_requests', 'framing_agreement_requests.purchase_request_id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::CONSUMABLE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('framing_agreement_requests.created_at', '>=', $request->from_date)
                        ->whereDate('framing_agreement_requests.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $farServiceCount = PurchaseRequestItem::
                join('framing_agreement_requests', 'framing_agreement_requests.purchase_request_id', '=', 'purchase_request_items.purchase_request_id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['purchase_request_items.category_id', PurchaseRequestCategory::SERVICE]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('framing_agreement_requests.created_at', '>=', $request->from_date)
                        ->whereDate('framing_agreement_requests.created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $totalItems = $farAssetsCount + $farConsumableCount + $farServiceCount;

                // Get average time
                // All framing agreement requests from when they are created to when their status becomes RFCIP_Form_Completed (1)
                $averageTimeToCompleteForm = FramingAgreementRequest::
                join('far_status_histories', 'far_status_histories.far_id', '=', 'framing_agreement_requests.id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['far_status_histories.far_status_id', farStatus::RFCIP_Form_Completed]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('far_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('far_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, framing_agreement_requests.created_at, far_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All framing agreement requests from when they are created to when their status becomes Confirm_RFCIP_signed (3)
                $averageTimeToSignForm = FramingAgreementRequest::
                join('far_status_histories', 'far_status_histories.far_id', '=', 'framing_agreement_requests.id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['far_status_histories.far_status_id', farStatus::Confirm_RFCIP_signed]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('far_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('far_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, framing_agreement_requests.created_at, far_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All framing agreement requests from when they are created to when their status becomes Approved_and_Proceed_to_issued_PO (18)
                $averageTimeToApprove = FramingAgreementRequest::
                join('far_status_histories', 'far_status_histories.far_id', '=', 'framing_agreement_requests.id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['far_status_histories.far_status_id', farStatus::Approved_and_Proceed_to_issued_PO]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('far_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('far_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, framing_agreement_requests.created_at, far_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All framing agreement requests from when they are created to when their status becomes Purchase_Order_Issued (21)
                $averageTimePOIssued = FramingAgreementRequest::
                join('far_status_histories', 'far_status_histories.far_id', '=', 'framing_agreement_requests.id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['far_status_histories.far_status_id', farStatus::Purchase_Order_Issued]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('far_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('far_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, framing_agreement_requests.created_at, far_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All framing agreement requests from when they are created to when their status becomes Confirm_Complete_Delivery (41) OR Confirm_Complete_Delivery (42)
                $averageTimeDelivery = FramingAgreementRequest::
                join('far_status_histories', 'far_status_histories.far_id', '=', 'framing_agreement_requests.id')
                ->where('framing_agreement_requests.cost_centre_id', $request->cost_centre_id)
                ->where(function($query){
                    $query->where('far_status_histories.far_status_id', farStatus::Confirm_Complete_Delivery)
                    ->orWhere('far_status_histories.far_status_id', farStatus::Confirm_Partial_Delivery);
                })
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('far_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('far_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, framing_agreement_requests.created_at, far_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                // All framing agreement requests from when they are created to when their status becomes RFCIP_complete_and_close (61)
                $averageTimeToComplete = FramingAgreementRequest::
                join('far_status_histories', 'far_status_histories.far_id', '=', 'framing_agreement_requests.id')
                ->where([['framing_agreement_requests.cost_centre_id', $request->cost_centre_id], ['far_status_histories.far_status_id', farStatus::RFCIP_complete_and_close]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('far_status_histories.created_at', '>=', $request->from_date)
                        ->whereDate('far_status_histories.created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, framing_agreement_requests.created_at, far_status_histories.created_at)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                $data = [
                    'stats' => [
                        'totalFAR' => $farCount,
                        'far_assets' => $farAssetsCount,
                        'far_consumable' => $farConsumableCount,
                        'far_service' => $farServiceCount,
                        'graph' => [
                            'assets_%age' => ($totalItems > 0) ? round(($farAssetsCount/$totalItems) * 100, 2) : 0, 
                            'consumable_%age' => ($totalItems > 0) ? round(($farConsumableCount/$totalItems) * 100, 2) : 0, 
                            'service_%age' => ($totalItems > 0) ? round(($farServiceCount/$totalItems) * 100, 2) : 0, 
                        ]
                    ],
                    'average_time' => [
                        'far_form_completed' => $averageTimeToCompleteForm,
                        'far_signed' => $averageTimeToSignForm,
                        'far_approve' => $averageTimeToApprove,
                        'far_po_issued' => $averageTimePOIssued,
                        'far_delivery' => $averageTimeDelivery,
                        'far_complete' => $averageTimeToComplete
                    ]
                ];
            }

            if($request->data == 'cost_centre_data')
            {
                // Cost Centre Users
                $costCentreUsers = User::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                // Cost Centre Users Weight from Organization
                $organizationID = CostCentre::where('id', $request->cost_centre_id)->pluck('organization_id')->first();
                $organizationUsers = User::where('organization_id', $organizationID)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();
                $costCentreUsersWeight = ($organizationUsers > 0) ? round(($costCentreUsers/$organizationUsers) * 100, 2) : 0;

                // Cost Centre Tasks
                $costCentreTasks = Task::where('cost_centre_id', $request->cost_centre_id)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();
                
                // Cost Centre Tasks Weight from Organization
                $organizationTasks = Task::where('organization_id', $organizationID)
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();
                $costCentreTasksWeight = ($organizationTasks > 0) ? round(($costCentreTasks/$organizationTasks) * 100, 2) : 0;

                // Cost Centre Rejected Tasks & Weight from Organization
                $costCentreRejectedTasks = Task::where([['cost_centre_id', $request->cost_centre_id], ['status', 2]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();
                $costCentreRejectedTasksPercent = ($costCentreTasks > 0) ? round(($costCentreRejectedTasks/$costCentreTasks) * 100, 2) : 0;
                $costCentreRejectedTasksWeight = ($organizationTasks > 0) ? round(($costCentreRejectedTasks/$organizationTasks) * 100, 2) : 0;

                // Cost Centre Average Task Completion Time
                $averageTaskCompletionTime = Task::where([['cost_centre_id', $request->cost_centre_id], ['task_completed_time', '!=', null], ['status', 3]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, task_start_time, task_completed_time)) as average_time'))
                ->first()->average_time/60/60; // Convert seconds into hours

                $data = [
                    'total_users' => $costCentreUsers,
                    'cost_centre_users_weight_%age' => $costCentreUsersWeight,
                    'tasks_stats' => [
                        'cost_centre_tasks' => $costCentreTasks,
                        'cost_centre_tasks_weight_%age' => $costCentreTasksWeight,
                        'cost_centre_rejected_tasks' => $costCentreRejectedTasks,
                        'cost_centre_rejected_task_%age' => $costCentreRejectedTasksPercent,
                        'cost_centre_rejected_task_weight_%age' => $costCentreRejectedTasksWeight,
                        'cost_centre_average_task_completion_time' => $averageTaskCompletionTime
                    ]
                ];
            }

            $response = ['status' => true ,'code' =>200 ,'message'=> __('messages.success'), 'data' => $data];
            return response($response); 
        }
        catch (\Exception $e)
        {
            $response = ['status' => false, 'code' => 500, 'message' => $e->getMessage()];
            return response($response);
        }
    }

    public function userPerformance(Request $request){
        $validated = $request->validate([
            'user_id'   => 'required',
            'data' => 'required|in:user_data,task_data,archive_data,meeting_data',
            'from_date'   => 'nullable|date_format:Y-m-d',
            'to_date'   => 'nullable|date_format:Y-m-d',
        ]);
        if (!$validated){
            return response()->json(['status' => false,'message'=> $validator->messages()]);
        }
        $lang = app()->getLocale();
        $userId = $request->user_id;
        $fromDate = $request->from_date;
        $toDate = $request->to_date;
        $orgId = Auth::user()->organization_id;
        $checkUser = User::where([['id',$userId],['organization_id',$orgId],['is_active',1]])->with('costCentre','permissions', 'permissions.permissionType')->select('id','cost_centre_id', 'first_name', 'last_name', 'id_number', 'department', 'job_title', 'created_at')->first();
        if($checkUser){
            if($request->data == 'user_data'){
                foreach ($checkUser->permissions as $permission) {
                    $localizedPermission = $permission->translate($lang);
                    $permission->name = $localizedPermission->name;
                }
                foreach ($checkUser->permissions as $permission) {
                    $permissionType = $permission->permissionType;
                    $localizedPermissionType = $permissionType->translate($lang);
                    $permissionType->name = $localizedPermissionType->name;
                }
                $data = [
                    'user_data' =>$checkUser
                ];
            }
           
            if($request->data == 'task_data'){
            
                $totalTasksSent = Task::where([['organization_id',$orgId],['task_receiver_id',$userId]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();
                if($totalTasksSent > 0){
                    $totalTasksAccepted = Task::where([['organization_id',$orgId],['task_receiver_id',$userId],['status',1]])
                    ->when(($request->from_date && $request->to_date), function($query) use ($request){
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    })
                    ->count();
                    $taskAcceptedPercentage = $totalTasksAccepted/$totalTasksSent * 100;
                    $totalTasksRejected = Task::where([['organization_id',$orgId],['task_receiver_id',$userId],['status',2]])
                    ->when(($request->from_date && $request->to_date), function($query) use ($request){
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    })
                    ->count();
                    $taskRejectedPercentage = $totalTasksRejected/$totalTasksSent * 100;

                    // Average Time to complete task
                    $averageTimeToCompleteTask = Task::
                    where([['organization_id',$orgId],['task_receiver_id',$userId]])
                    ->when(($request->from_date && $request->to_date), function($query) use ($request){
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    })
                    ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, tasks.task_start_time, tasks.task_completed_time)) as average_time'))
                    ->first()->average_time/86400; // Convert seconds into days
                }else{
                    $totalTasksAccepted = 0 ;
                    $taskAcceptedPercentage = 0 ;
                    $totalTasksRejected = 0 ;
                    $taskRejectedPercentage = 0 ;
                    $averageTimeToCompleteTask = 0;
                }
                $tasks = Task::where([['organization_id',$orgId],['task_receiver_id',$userId]])->with('taskSender')
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'application_module_id', 'module_status_id', 'task_sender_id', 'task_start_time', 'task_completed_time')
                ->latest()->get();
                
                foreach($tasks as $task){
                    $module = $task->application_module_id;  
                    $statusId = $task->module_status_id;  
                    if($module == ApplicationModule::PR){
                        $statusFind = PurchaseRequestStatus::find($statusId);
                        $task->task_name = $statusFind->translate($lang)->name;
                    }elseif($module == ApplicationModule::DPO){
                        $statusFind = DirectPurchaseOrderStatus::find($statusId);
                        $task->task_name = $statusFind->translate($lang)->name;
                    }elseif($module == ApplicationModule::FAR){ 
                        $statusFind = farStatus::find($statusId);
                        $task->task_name = $statusFind->translate($lang)->name;
                    }elseif($module == ApplicationModule::OA){ 
                        if($task->task_type_id == TaskType::ADDED_COMMITTEE_MEMBER)
                        {
                            $task->task_name = __('messages.task_offer_analysis_committee_member');
                        }
                        else if($task->task_type_id == TaskType::ADDED_AUTHORIZER)
                        {
                            $task->task_name = __('messages.task_offer_analysis_authorizer');
                        }
                        else if($task->task_type_id == TaskType::ASSIGNED_EXECUTOR)
                        {
                            $task->task_name = __('messages.task_offer_analysis_executor');
                        }
                    }
                }

                $data = [
                    'total_tasks_sent'=>$totalTasksSent,
                    'tasks_accepted'=>$totalTasksAccepted,
                    'tasks_accepted_percentage'=>$taskAcceptedPercentage,
                    'tasks_rejected'=>$totalTasksRejected,
                    'tasks_rejected_percentage'=>$taskRejectedPercentage,
                    'average_time_to_complete_task' => $averageTimeToCompleteTask,
                    'tasks'=>$tasks,
                ];
            }
            if($request->data == 'archive_data'){
                $totalArchives = Archive::where([['organization_id',$orgId],['created_by',$userId]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $letterArchives = Archive::where([['organization_id',$orgId],['created_by',$userId],['archive_type_id',1]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $requestArchives = Archive::where([['organization_id',$orgId],['created_by',$userId],['archive_type_id',2]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $documentArchives = Archive::where([['organization_id',$orgId],['created_by',$userId],['archive_type_id',3]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->count();

                $archives = Archive::with('archiveType')->where([['organization_id',$orgId],['created_by',$userId]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'subject', 'archive_type_id', 'date', 'created_at')
                ->latest()
                ->get();

                foreach($archives as $archive)
                {
                    $archive->archive_type_name = $archive->archiveType->translate(app()->getLocale())->name;
                    unset($archive->archiveType);
                }

                $data = [
                    'total_archives' => $totalArchives,
                    'letter_archives' => $letterArchives,
                    'request_archives' => $requestArchives,
                    'document_archives' => $documentArchives,
                    'archives' => $archives
                ];
            }

            if($request->data == 'meeting_data')
            {
                $meetingsReceived = MeetingParticipants::where([['organization_id', $orgId], ['participant_id', $userId]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'user_id', 'meeting_id', 'participant_id')
                ->count();

                $meetingsCreated = Meeting::where([['organization_id', $orgId], ['user_id', $userId]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'user_id', 'meeting_id', 'participant_id')
                ->count();

                $meetingsRejected = MeetingParticipants::where([['organization_id', $orgId], ['participant_id', $userId], ['rejected', 1]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'user_id', 'meeting_id', 'participant_id')
                ->count();

                $meetingsRescheduled = MeetingReshcedule::where([['organization_id', $orgId], ['participant_id', $userId]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'user_id', 'meeting_id', 'participant_id')
                ->count();
                $onTimeMeetingsAttended = MeetingParticipants::where([['organization_id', $orgId], ['participant_id', $userId], ['accepted', 1],['is_late',0]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'user_id', 'meeting_id', 'participant_id')
                ->count(); 
                $lateMeetingsAttended = MeetingParticipants::where([['organization_id', $orgId], ['participant_id', $userId], ['accepted', 1],['is_late',1]])
                ->when(($request->from_date && $request->to_date), function($query) use ($request){
                    $query->where(function($query) use ($request) {
                        $query->whereDate('created_at', '>=', $request->from_date)
                        ->whereDate('created_at', '<=', $request->to_date);
                    });
                })
                ->select('id', 'user_id', 'meeting_id', 'participant_id')
                ->count(); 

                $data = [
                    'meetings_received' => $meetingsReceived,
                    'meetings_created' => $meetingsCreated,
                    'meetings_rejected' => $meetingsRejected,
                    'meetings_rescheduled' => $meetingsRescheduled,
                    'meetings_attended_on_time' => $onTimeMeetingsAttended,
                    'meetings_attended_late' => $lateMeetingsAttended,
                ];
            }

            $response = ['status' => true ,'code' =>200 ,'message'=> __('messages.success'), 'data' => $data];
            return response($response); 
        }else{
            $response = ['status' => true, 'code' => 100, 'message' => 'Invalid user id'];
            return response($response);
        }
    }

    public function commonReport(Request $request)
    {
        $validated = $request->validate([
            'from_date'   => 'required|date_format:Y-m-d',
            'to_date'   => 'required|date_format:Y-m-d',
            'data' => 'required|in:stats,cost_centre_budget_and_transactions,time_and_spent_budget_comparison',
            'type' => 'required|in:monthly,quarterly,semi-annual,annual'
        ]);
        
        if (!$validated)
        {
            return response()->json(['status' => false,'message'=> $validator->messages()]);
        }
        
        try
        {
            $orgId = Auth::user()->organization_id;
            $fromDate =  $request->from_date;
            $toDate =  $request->to_date;

            // If user is owner, then get all of its organization's cost centres
            if(Auth::user()->user_type_id == UserType::IS_OWNER)
            {
                $costCentreIDs = CostCentre::where([['organization_id', $orgId], ['is_active', 1]])->pluck('id')->toArray();
            }
            else
            {
                $costCentreIDs = CostCentre::where([['id', Auth::user()->cost_centre_id], ['is_active', 1]])->pluck('id')->toArray();
            }

            if($request->data =='stats')
            {
                $totalCostCentres = CostCentre::whereIn('id', $costCentreIDs)->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)->count();

                $itemsRequests = ItemRequest::whereIn('cost_centre_id', $costCentreIDs)->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)->count();
                
                $PRs = PurchaseRequest::whereIn('cost_centre_id', $costCentreIDs)->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)->count();
                
                $DPOS = DirectPurchaseOrder::whereIn('cost_centre_id', $costCentreIDs)->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)->count();
                
                $FARS = FramingAgreementRequest::whereIn('cost_centre_id', $costCentreIDs)->whereDate('created_at', '>=', $fromDate)
                ->whereDate('created_at', '<=', $toDate)->count();
                $totalTransactions = $itemsRequests + $PRs + $DPOS + $FARS;
                
                // budget
                $costCentreBudgets = CostCentreBudget::whereIn('cost_centre_id', $costCentreIDs)
                ->where(function($query) use ($fromDate, $toDate) {
                    $query->where(function($query) use ($fromDate, $toDate){
                        $query->whereDate('start_date', '>=', $fromDate)
                        ->whereDate('end_date', '<=', $toDate);
                    })
                    ->orWhere(function($query) use ($fromDate, $toDate) {
                        $query->whereDate('end_date', '>=', $fromDate)
                        ->whereDate('start_date', '<=', $toDate);
                    });
                })
                ->select('id', 'amount')
                ->get();

                $totalBudget = $costCentreBudgets->sum('amount');
                $costCenterBudgetIDs = $costCentreBudgets->pluck('id')->toArray();
                
                $spentBudget = BudgetSpent::whereIn('cost_centre_budget_id', $costCenterBudgetIDs)
                ->where(function($query) use ($fromDate, $toDate){
                    $query->whereDate('created_at', '>=', $fromDate)
                    ->whereDate('created_at', '<=', $toDate);
                })
                ->sum('deducted_amount');

                // If there is spent budget transactions, then calculate rest budget.
                // Else rest budget will be equal to total budget
                if($spentBudget > 0)
                {
                    $restBudget = 0;

                    // To get the rest budget
                    foreach($costCenterBudgetIDs as $budgetID)
                    {
                        $checkRestBudget = BudgetSpent::where('cost_centre_budget_id', $budgetID)
                        ->where(function($query) use ($fromDate, $toDate){
                            $query->whereDate('created_at', '>=', $fromDate)
                            ->whereDate('created_at', '<=', $toDate);
                        })
                        ->first();

                        // If there exists any spent budget transaction against a specific budget, then sum rest budget from budget_spents table.
                        // Else get the rest budget from cost_centre_budgets table.
                        if($checkRestBudget)
                        {   
                            $restBudget += BudgetSpent::where('cost_centre_budget_id', $budgetID)
                            ->where(function($query) use ($fromDate, $toDate){
                                $query->whereDate('created_at', '>=', $fromDate)
                                ->whereDate('created_at', '<=', $toDate);
                            })
                            ->sum('rest_budget');
                        }
                        else
                        {
                            $restBudget += CostCentreBudget::where('id', $budgetID)
                            ->where(function($query) use ($fromDate, $toDate) {
                                $query->where(function($query) use ($fromDate, $toDate){
                                    $query->whereDate('start_date', '>=', $fromDate)
                                    ->whereDate('end_date', '<=', $toDate);
                                })
                                ->orWhere(function($query) use ($fromDate, $toDate) {
                                    $query->whereDate('end_date', '>=', $fromDate)
                                    ->whereDate('start_date', '<=', $toDate);
                                });
                            })
                            ->pluck('rest_budget')
                            ->first();
                        }
                    }
                }
                else
                {
                    $restBudget = $totalBudget;
                }
                
                $data = [
                    'stats' => [
                        'number_of_cost_centres' =>$totalCostCentres,
                        'total_transactions' =>($totalTransactions >0) ? $totalTransactions :0,
                        'ir' =>$itemsRequests,
                        'pr' =>$PRs,
                        'dpo' =>$DPOS,
                        'far' =>$FARS,
                        'ir_percentage' =>($totalTransactions >0) ? $itemsRequests/$totalTransactions *100 : 0,
                        'pr_percentage' =>($totalTransactions >0) ? $PRs/$totalTransactions *100 : 0,
                        'dpo_percentage' =>($totalTransactions >0) ? $DPOS/$totalTransactions *100 : 0,
                        'far_percentage' =>($totalTransactions >0) ? $FARS/$totalTransactions *100 :0,
                        'total_budget' =>$totalBudget,
                        'spent_budget' =>$spentBudget,
                        'rest_budget' =>$restBudget,
                        'spent_budget_percentage' =>($totalBudget >0) ? round($spentBudget/$totalBudget *100,2) :0,
                        'rest_budget_percentage' =>($totalBudget >0) ? round($restBudget/$totalBudget *100,2) :0,
                    ],
                ];
            }

            if($request->data =='cost_centre_budget_and_transactions')
            {
                $lang = app()->getLocale();
                
                // Get all  Cost Centres
                $costCentres = CostCentre::whereIn('id', $costCentreIDs)
                ->select('id',  ($lang == 'en' ? 'en_name' : 'ar_name') . ' as name')
                ->latest()->get();

                foreach($costCentres as $costCentre)
                {
                    // Get all cost centre budgets
                    $costCentreBudgets = CostCentreBudget::where('cost_centre_id', $costCentre->id)
                    ->where(function($query) use ($fromDate, $toDate) {
                        $query->where(function($query) use ($fromDate, $toDate){
                            $query->whereDate('start_date', '>=', $fromDate)
                            ->whereDate('end_date', '<=', $toDate);
                        })
                        ->orWhere(function($query) use ($fromDate, $toDate) {
                            $query->whereDate('end_date', '>=', $fromDate)
                            ->whereDate('start_date', '<=', $toDate);
                        });
                    })
                    ->select('id', 'amount')
                    ->get();

                    $totalBudget = $costCentreBudgets->sum('amount');
                    $costCenterBudgetIDs = $costCentreBudgets->pluck('id')->toArray();
                
                    $spentBudget = BudgetSpent::whereIn('cost_centre_budget_id', $costCenterBudgetIDs)
                    ->where(function($query) use ($fromDate, $toDate){
                        $query->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                    })
                    ->sum('deducted_amount');

                    // If there is spent budget transactions, then calculate rest budget.
                    // Else rest budget will be equal to total budget
                    if($spentBudget > 0)
                    {
                        $restBudget = 0;

                        // To get the rest budget
                        foreach($costCenterBudgetIDs as $budgetID)
                        {
                            $checkRestBudget = BudgetSpent::where('cost_centre_budget_id', $budgetID)
                            ->where(function($query) use ($fromDate, $toDate){
                                $query->whereDate('created_at', '>=', $fromDate)
                                ->whereDate('created_at', '<=', $toDate);
                            })
                            ->first();

                            // If there exists any spent budget transaction against a specific budget, then sum rest budget from budget_spents table.
                            // Else get the rest budget from cost_centre_budgets table.
                            if($checkRestBudget)
                            {   
                                $restBudget += BudgetSpent::where('cost_centre_budget_id', $budgetID)
                                ->where(function($query) use ($fromDate, $toDate){
                                    $query->whereDate('created_at', '>=', $fromDate)
                                    ->whereDate('created_at', '<=', $toDate);
                                })
                                ->sum('rest_budget');
                            }
                            else
                            {
                                $restBudget += CostCentreBudget::where('id', $budgetID)
                                ->where(function($query) use ($fromDate, $toDate) {
                                    $query->where(function($query) use ($fromDate, $toDate){
                                        $query->whereDate('start_date', '>=', $fromDate)
                                        ->whereDate('end_date', '<=', $toDate);
                                    })
                                    ->orWhere(function($query) use ($fromDate, $toDate) {
                                        $query->whereDate('end_date', '>=', $fromDate)
                                        ->whereDate('start_date', '<=', $toDate);
                                    });
                                })
                                ->pluck('rest_budget')
                                ->first();
                            }
                        }
                    }
                    else
                    {
                        $restBudget = $totalBudget;
                    }

                    $costCentre->amount = $totalBudget;
                    $costCentre->spent = $spentBudget;
                    $costCentre->rest = $restBudget;
                }

                $allCostCentresBudget = $costCentres->pluck('amount')->sum();
                $costCentresBudget = $costCentres->map(function($item) use($allCostCentresBudget)
                {
                    $budgetWeight = ($allCostCentresBudget > 0) ? round(($item->amount/$allCostCentresBudget) * 100, 2) : 0;
                    
                    $result = [
                        'id' => $item->id,
                        'name' => $item->name,
                        'budget_weight_%age' => $budgetWeight,
                        'amount' => $item->amount,
                        'spent' => $item->spent,
                        'rest' => $item->rest,
                        'spent_%age' => ($item->amount > 0) ? round(($item->spent/$item->amount) * 100, 2) : 0,
                        'rest_%age' => ($item->amount > 0) ? round(($item->rest/$item->amount) * 100, 2) : 0,
                    ];

                    return $result;
                });

                //total transactions for all cost centres
                $totalTransactions = CostCentre::whereIn('id', $costCentreIDs)
                ->withCount([
                    'itemRequests'=>function($query)use ($fromDate,$toDate){
                    $query->whereDate('created_at', '>=', $fromDate)
                    ->whereDate('created_at', '<=', $toDate);
                    }, 
                    'PRs'=>function($query)use ($fromDate,$toDate){
                        $query->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                    },
                    'DPOs'=>function($query)use ($fromDate,$toDate){
                        $query->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                    },
                    'FARs'=>function($query)use ($fromDate,$toDate){
                        $query->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                    },
                ])
                
                ->get()
                ->sum(function($costCentre) {
                    return $costCentre->item_requests_count + $costCentre->p_rs_count + $costCentre->d_p_os_count + $costCentre->f_a_rs_count;
                });

                // Calculating Cost Centre Transactions
                $costCentreTransactions = CostCentre::select('id',($lang == 'en' ? 'en_name' : 'ar_name') . ' as name','initial_name')->whereIn('id', $costCentreIDs)
                ->withCount([
                    'itemRequests'=>function($query)use ($fromDate,$toDate){
                    $query->whereDate('created_at', '>=', $fromDate)
                    ->whereDate('created_at', '<=', $toDate);
                    }, 
                    'PRs'=>function($query)use ($fromDate,$toDate){
                        $query->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                    },
                    'DPOs'=>function($query)use ($fromDate,$toDate){
                        $query->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                    },
                    'FARs'=>function($query)use ($fromDate,$toDate){
                        $query->whereDate('created_at', '>=', $fromDate)
                        ->whereDate('created_at', '<=', $toDate);
                    },
                ])->get();
            
                foreach($costCentreTransactions as $transaction)
                {
                    $sumOfTransactions =  $transaction->item_requests_count + $transaction->p_rs_count + $transaction->d_p_os_count + $transaction->f_a_rs_count;
                    $transaction->number_of_transactions = $sumOfTransactions;
                    $irPercentage =  ($sumOfTransactions > 0 ) ? round($transaction->item_requests_count/$sumOfTransactions *100, 2) : 0;
                    $transaction->ir_percentage = $irPercentage;

                    $prPercentage =  ($sumOfTransactions > 0 ) ? round($transaction->p_rs_count/$sumOfTransactions *100 ,2): 0;
                    $transaction->pr_percentage = $prPercentage;

                    $dpoPercentage =  ($sumOfTransactions > 0 ) ? round($transaction->d_p_os_count/$sumOfTransactions *100 ,2): 0;
                    $transaction->dpo_percentage = $dpoPercentage;

                    $farPercentage =  ($sumOfTransactions > 0 ) ? round($transaction->f_a_rs_count/$sumOfTransactions *100 ,2): 0;
                    $transaction->far_percentage = $farPercentage;

                    // calculating the weight of the cost centre
                    $weight = ($totalTransactions > 0) ? round(($sumOfTransactions / $totalTransactions) * 100, 2) : 0;
                    $transaction->weight_percentage = $weight;
                }

                $data = [
                    'cost_centres_budget' =>$costCentresBudget,
                    'cost_centre_transactions' =>$costCentreTransactions,
                ];
            }

            if($request->data =='time_and_spent_budget_comparison')
            {
                
                if($request->type == 'monthly')
                {
                    // coverting date to month
                    $date = Carbon::createFromFormat('Y-m-d', $fromDate);
                    $monthNumber= $date->format('m'); // number format
                    $year= $date->format('Y'); // number format

                    //to show days month wise
                    for($i=0; $i<$monthNumber; $i++ )
                    {
                        $requestedMonth = $i+1;
                        $averageTimeCompleteIR[$i] = ItemRequest::
                            join('item_request_status_histories', 'item_requests.id', '=', 'item_request_status_histories.item_request_id')
                            ->whereIn('item_requests.cost_centre_id', $costCentreIDs)
                            ->where('item_request_status_histories.item_request_status_id', ItemRequestStatus::ITEM_DELIVERED)->whereMonth('item_request_status_histories.created_at',$requestedMonth)
                            ->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, item_requests.created_at, item_request_status_histories.created_at)),2) as no_of_days'))
                        ->first();
                        
                        $avrageTimeCompletePR[$i] = PurchaseRequest::join('purchase_request_status_histories','purchase_requests.id','=','purchase_request_status_histories.purchase_request_id')
                        ->whereIn('purchase_requests.cost_centre_id', $costCentreIDs)->where('purchase_request_status_histories.purchase_request_status_id', PurchaseRequestStatus::Complete_and_close)->whereMonth('purchase_request_status_histories.created_at',$requestedMonth)->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, purchase_requests.created_at, purchase_request_status_histories.created_at)),2) as no_of_days'))->first();
                        
                        $averageTimeCompleteDPO[$i] = DirectPurchaseOrder::join('d_p_o_status_histories','direct_purchase_orders.id','=','d_p_o_status_histories.dpo_id')
                        ->whereIn('direct_purchase_orders.cost_centre_id', $costCentreIDs)->where('d_p_o_status_histories.dpo_status_id', DirectPurchaseOrderStatus::PAYMENT_CYCLE_START)->whereMonth('d_p_o_status_histories.created_at',$requestedMonth)->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, direct_purchase_orders.created_at, d_p_o_status_histories.created_at)),2) as no_of_days'))->first();
                        
                        $averageTimeCompleteFAR[$i] = FramingAgreementRequest::join('far_status_histories','framing_agreement_requests.id','=','far_status_histories.far_id')
                        ->whereIn('framing_agreement_requests.cost_centre_id', $costCentreIDs)->where('far_status_histories.far_status_id', farStatus::RFCIP_complete_and_close)->whereMonth('far_status_histories.created_at',$requestedMonth)->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, framing_agreement_requests.created_at, far_status_histories.created_at)),2) as no_of_days'))->first();
                    }

                    // spent budget comparison starts
                    $costCentreBudgets =  CostCentreBudget::whereIn('cost_centre_id', $costCentreIDs)
                    ->where(function($query) use ($fromDate, $toDate) {
                        $query->where(function($query) use ($fromDate, $toDate){
                            $query->whereDate('start_date', '>=', $fromDate)
                            ->whereDate('end_date', '<=', $toDate);
                        })
                        ->orWhere(function($query) use ($fromDate, $toDate) {
                            $query->whereDate('end_date', '>=', $fromDate)
                            ->whereDate('start_date', '<=', $toDate);
                        });
                    })
                    ->get();

                    $totalBudget = $costCentreBudgets->sum('amount');
                    $costCentreBudgetIDs = $costCentreBudgets->pluck('id')->toArray();
                    
                    $totalSpentBudget =  BudgetSpent::whereIn('cost_centre_budget_id', $costCentreBudgetIDs)
                    ->where(function($query) use($monthNumber){
                        $query->whereMonth('created_at', '>=', 1)
                        ->whereMonth('created_at', '<=', $monthNumber);
                    })
                    ->whereYear('created_at',$year)
                    ->sum('deducted_amount');
                    
                    $totalSpentPercentage = ($totalBudget > 0) ? round($totalSpentBudget/$totalBudget * 100, 2) : 0;

                    for($i=0; $i<$monthNumber; $i++)
                    {
                        $requestedMonth =  $i+1;
                        $monthlyBudgetSpent[$i]['amount'] = BudgetSpent::whereIn('cost_centre_budget_id', $costCentreBudgetIDs)
                        ->whereMonth('created_at', $requestedMonth)
                        ->whereYear('created_at',$year)
                        ->sum('deducted_amount');
                        
                        $percentage = ($totalBudget > 0) ? round(($monthlyBudgetSpent[$i]['amount']/$totalBudget) * 100, 2) : 0;
                        $monthlyBudgetSpent[$i]['percentage'] = $percentage;
                    }
                    $data = [
                        'time_avg_comparison'=> [
                            'avg_time_complete_ir'=>$averageTimeCompleteIR,
                            'avg_time_complete_pr'=>$avrageTimeCompletePR,
                            'avg_time_complete_dpo'=>$averageTimeCompleteDPO,
                            'avg_time_complete_far'=>$averageTimeCompleteFAR,
                        ],
                        'total_budget' => $totalBudget,
                        'total_spent_budget' => $totalSpentBudget,
                        'total_spent_budget_%age' => $totalSpentPercentage,
                        'spent_budget_comparison'=> $monthlyBudgetSpent,
                    ];

                }
                else if($request->type == 'quarterly' || $request->type == 'semi-annual' || $request->type == 'annual')
                {
                    $monthNumber = Carbon::createFromFormat('Y-m-d', $toDate)->format('m');
                    $year = Carbon::createFromFormat('Y-m-d', $toDate)->format('Y');
                    $monthsArray = range(1, $monthNumber); // Result will be like : [1,2,3] if toMonth is 3
                    // If type is quarterly, then create 3 months chunks.
                    // If semi-annual, then create 6 months chunks.
                    // If annual, then create 12 months chunk.
                    $monthsChunks = ($request->type == 'quarterly') ? array_chunk($monthsArray, 3) : ($request->type == 'semi-annual' ? array_chunk($monthsArray, 6) : ($request->type == 'annual' ? array_chunk($monthsArray, 12) : []));
                    foreach($monthsChunks as $key => $chunk )
                    {
                            $averageTimeCompleteIR[$key] = ItemRequest::
                            join('item_request_status_histories', 'item_requests.id', '=', 'item_request_status_histories.item_request_id')
                            ->whereIn('item_requests.cost_centre_id', $costCentreIDs)
                            ->where('item_request_status_histories.item_request_status_id', ItemRequestStatus::ITEM_DELIVERED)
                            ->whereBetween(DB::raw('MONTH(item_request_status_histories.created_at)'), [$chunk[0], $chunk[count($chunk) - 1]])
                            ->whereYear('item_request_status_histories.created_at',$year)
                            ->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, item_requests.created_at, item_request_status_histories.created_at)),2) as no_of_days'))
                            ->first();
                            $avrageTimeCompletePR[$key] = PurchaseRequest::join('purchase_request_status_histories','purchase_requests.id','=','purchase_request_status_histories.purchase_request_id')
                            ->whereIn('purchase_requests.cost_centre_id', $costCentreIDs)->where('purchase_request_status_histories.purchase_request_status_id', PurchaseRequestStatus::Complete_and_close)
                            ->whereBetween(DB::raw('MONTH(purchase_request_status_histories.created_at)'), [$chunk[0], $chunk[count($chunk) - 1]])
                            ->whereYear('purchase_request_status_histories.created_at',$year)->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, purchase_requests.created_at, purchase_request_status_histories.created_at)),2) as no_of_days'))->first();

                            $averageTimeCompleteDPO[$key] = DirectPurchaseOrder::join('d_p_o_status_histories','direct_purchase_orders.id','=','d_p_o_status_histories.dpo_id')
                            ->whereIn('direct_purchase_orders.cost_centre_id', $costCentreIDs)->where('d_p_o_status_histories.dpo_status_id', DirectPurchaseOrderStatus::PAYMENT_CYCLE_START) ->whereBetween(DB::raw('MONTH(d_p_o_status_histories.created_at)'), [$chunk[0], $chunk[count($chunk) - 1]])
                            ->whereYear('d_p_o_status_histories.created_at',$year)->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, direct_purchase_orders.created_at, d_p_o_status_histories.created_at)),2) as no_of_days'))->first();

                            $averageTimeCompleteFAR[$key] = FramingAgreementRequest::join('far_status_histories','framing_agreement_requests.id','=','far_status_histories.far_id')
                            ->whereIn('framing_agreement_requests.cost_centre_id', $costCentreIDs)->where('far_status_histories.far_status_id', farStatus::RFCIP_complete_and_close)
                            ->whereBetween(DB::raw('MONTH(far_status_histories.created_at)'), [$chunk[0], $chunk[count($chunk) - 1]])
                            ->whereYear('far_status_histories.created_at',$year)
                            ->select(DB::raw('ROUND(AVG(TIMESTAMPDIFF(DAY, framing_agreement_requests.created_at, far_status_histories.created_at)),2) as no_of_days'))->first();
                    
                    }
                    
                    // spent budget comparison starts
                    $costCentreBudgets =  CostCentreBudget::whereIn('cost_centre_id', $costCentreIDs)
                    ->where(function($query) use ($fromDate, $toDate) {
                        $query->where(function($query) use ($fromDate, $toDate){
                            $query->whereDate('start_date', '>=', $fromDate)
                            ->whereDate('end_date', '<=', $toDate);
                        })
                        ->orWhere(function($query) use ($fromDate, $toDate) {
                            $query->whereDate('end_date', '>=', $fromDate)
                            ->whereDate('start_date', '<=', $toDate);
                        });
                    })
                    ->get();

                    $totalBudget = $costCentreBudgets->sum('amount');
                    $costCentreBudgetIDs = $costCentreBudgets->pluck('id')->toArray();
                    
                    $totalSpentBudget =  BudgetSpent::whereIn('cost_centre_budget_id', $costCentreBudgetIDs)
                    ->where(function($query) use($monthNumber){
                        $query->whereMonth('created_at', '>=', 1)
                        ->whereMonth('created_at', '<=', $monthNumber);
                    })
                    ->whereYear('created_at', $year)
                    ->sum('deducted_amount');
                    
                    $totalSpentPercentage = ($totalBudget > 0) ? round($totalSpentBudget/$totalBudget * 100, 2) : 0;

                    foreach($monthsChunks as $index => $chunk)
                    {
                        $spent_budget_comparison[$index]['amount'] = BudgetSpent::whereIn('cost_centre_budget_id', $costCentreBudgetIDs)
                        // For Quarterly, It will search for months like : 1-3 or 3-6 or 6-9 or 9-12
                        // For Semi-Annual, It will search for months like : 1-6 or 7-12
                        // For Annual, It will search for months like : 1-12
                        ->whereBetween(DB::raw('MONTH(created_at)'), [$chunk[0], $chunk[count($chunk) - 1]])
                        ->whereYear('created_at', $year)
                        ->sum('deducted_amount');
                        
                        $percentage = ($totalBudget > 0) ? round(($spent_budget_comparison[$index]['amount']/$totalBudget) * 100, 2) : 0;
                        $spent_budget_comparison[$index]['percentage'] = $percentage;
                    }

                    $data = [
                        'time_avg_comparison'=> [
                            'avg_time_complete_ir'=>$averageTimeCompleteIR,
                            'avg_time_complete_pr'=>$avrageTimeCompletePR,
                            'avg_time_complete_dpo'=>$averageTimeCompleteDPO,
                            'avg_time_complete_far'=>$averageTimeCompleteFAR,
                        ],
                        'total_budget' => $totalBudget,
                        'total_spent_budget' => $totalSpentBudget,
                        'total_spent_budget_%age' => $totalSpentPercentage,
                        'spent_budget_comparison'=> $spent_budget_comparison,
                    ];
                }
            }

            $response = ['status' => true, 'code' => 200, 'message' => __('messages.success'), 'data' => $data];
            return response($response); 
        }
        catch(\Exception $e)
        {
            $response = ['status' => false, 'code' =>500, 'message' => $e->getMessage()];
            return response($response);
        }
    }
}
