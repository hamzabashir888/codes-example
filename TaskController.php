<?php

namespace App\Http\Controllers\api;

use App\Models\Task;
use App\Models\User;
use App\Models\TaskType;
use App\Models\farStatus;
use Illuminate\Http\Request;
use App\Models\OfferAnalysis;
use App\Models\PurchaseRequest;
use App\Models\DPOStatusHistory;
use App\Models\farStatusHistory;
use App\Models\ApplicationModule;
use Illuminate\Support\Facades\DB;
use App\Models\DirectPurchaseOrder;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\PurchaseRequestStatus;
use App\Models\ApplicationModuleLabel;
use App\Models\OfferAnalysisAuthorizer;
use App\Notifications\TaskNotification;
use App\Models\DirectPurchaseOrderStatus;
use App\Models\OfferAnalysisCommitteeMember;
use App\Models\PurchaseRequestStatusHistory;
use Illuminate\Support\Facades\Notification;

class TaskController extends Controller
{
    public function allTasksList() {
        try{
            $lang = app()->getLocale();
            $tasks = Task::where([['organization_id',Auth::user()->organization_id],['task_receiver_id',Auth::id()]])
            ->where('status',0)->with('applicationModule','taskSender','taskReceiver')
            ->latest()->paginate(20);
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
                    $task->applicationModule->translate($lang)->name;
                }
            if(count($tasks)>0){
                $response = ['status' => true, 'code' => 200, 'message' => __('messages.success'),'data'=> $tasks];
                return response($response);
            }else{
                $response = ['status' => true, 'code' => 100, 'message' => __('messages.no_record')];
                return response($response);
            }   
        }catch(\Exception $e){
            return  $response = ['status' => false ,'code' =>500  ,'message'=> $e->getMessage() ];
        }
    }  
    public function taskStats(){
      
        try{
            $orgId = Auth::user()->organization_id;
            $query = Task::where([['organization_id',$orgId],['task_receiver_id',Auth::id()]])->get();
            $totalTasks = $query->count();
            $newTasks = $query->where('status',0)->count();
            $completedTasks = $query->where('status',3)->count();
            $rejectedTasks = $query->where('status',2)->count(); // 2 for rejected 
            // dd($orgId, Auth::id() ,$rejectedTasks);
            $data = [
                'total_tasks' => $totalTasks,
                'new_tasks' => $newTasks,
                'completed_tasks' => $completedTasks,
                'rejected_tasks' => $rejectedTasks,
            ];
            return $response = [ 'status' => true,'code' => 200,'message' => __('messages.success'),'data' => $data ];
            
        }catch(\Exception $e){
            return  $response = ['status' => false ,'code' =>500  ,'message'=> $e->getMessage() ];
        }
        
    }
    public function updateStatus(Request $request){
        // in case of complete status, the receiver will firstly accept the task and then after completing that task (updating next status from module), the task will be completed.
        // in case of reject status, if the receiver reject the task, the status of that task will be rejected and the status from that module will be reverted and will create a history for its performance.
        DB::beginTransaction();
        try{
            $validated = $request->validate([
                'task_id'   => 'required',
            ]);
            if (!$validated){
                return response()->json(['status' => false,'message'=> $validator->messages()]);
            }
            $lang = app()->getLocale();
            $taskId = $request->task_id;
            $orgId = Auth::user()->organization_id;
            $status = $request->status;
            $checkTask = Task::where([['id',$taskId],['organization_id',$orgId],['task_receiver_id',Auth::id()]])->first();
            if($checkTask){
                $module = $checkTask->application_module_id; 
                $applicationModuleLabels = ApplicationModuleLabel::where('reference_id', $module)->get();
                $applicationModuleLabelEN = $applicationModuleLabels->where('language_code', 'en')->pluck('name')->first();
                $applicationModuleLabelAR = $applicationModuleLabels->where('language_code', 'ar')->pluck('name')->first();
                if($module == ApplicationModule::PR){
                    $taskNameEN = $checkTask->purchaseRequest->pRStatus->translate('en')->name;
                    $taskNameAR = $checkTask->purchaseRequest->pRStatus->translate('ar')->name;
                    $reference = $checkTask->purchaseRequest->purchase_reference;
                }elseif($module == ApplicationModule::DPO){
                    $taskNameEN = $checkTask->dpo->dpoStatus->translate('en')->name;
                    $taskNameAR = $checkTask->dpo->dpoStatus->translate('ar')->name;
                    $reference = $checkTask->dpo->dpo_reference;
                }elseif($module == ApplicationModule::FAR){
                    $taskNameEN = $checkTask->far->farStatus->translate('en')->name;
                    $taskNameAR = $checkTask->far->farStatus->translate('ar')->name;
                    $reference = $checkTask->far->rfcip;
                }elseif($module == ApplicationModule::OA){
                    $taskNameEN = null;
                    $taskNameAR = null;
                    $getOfferAnalysisData = OfferAnalysis::with('purchaseRequest')->where('id', $checkTask->offer_analysis_id)->first();
                    $reference = $getOfferAnalysisData->purchaseRequest->purchase_reference;
                }
                else{
                    $taskNameEN = null;
                    $taskNameAR = null;
                    $reference = null;
                }

               if($status == Task::ACCEPTED){
                    $checkTask->update(['status'=>1]);  //when next status will be done from the module then its status will be completed.
                    //NOtification Scenario Rjected 
                    $receiver = User::find($checkTask->task_sender_id);
                    if($receiver){
                        $receiverLang = $receiver->languageType->code;
                        $type = 23;   // for  TASK_ACCEPTED

                        if($module == ApplicationModule::OA)
                        {
                            if($checkTask->task_type_id == TaskType::ADDED_COMMITTEE_MEMBER)
                            {
                                OfferAnalysisCommitteeMember::where([['offer_analysis_id', $checkTask->offer_analysis_id], ['user_id', Auth::user()->id]])->update(['decision' => 1, 'decision_time' => now()]); // User has accepted offer analysis request

                                $getRole = OfferAnalysisCommitteeMember::with('offerAnalysisCommitteeRole.labels')->where([['offer_analysis_id', $checkTask->offer_analysis_id], ['user_id', Auth::user()->id]])->first();
                                $roleEn = $getRole->offerAnalysisCommitteeRole->translate('en')->name;
                                $roleAr = $getRole->offerAnalysisCommitteeRole->translate('ar')->name;

                                $notificationData = [
                                    'title' => [
                                        'en' => trans('messages.task_accepted_offer_analysis_committee_member', [], 'en'),
                                        'ar' => trans('messages.task_accepted_offer_analysis_committee_member', [], 'ar'),
                                    ],
                                    'body' => [
                                        'en' => Auth::user()->first_name.' '.trans('messages.task_accepted_offer_analysis_committee_member_body', ['role' => $roleEn], 'en'),
                                        'ar' => Auth::user()->first_name.' '.trans('messages.task_accepted_offer_analysis_committee_member_body', ['role' => $roleAr], 'ar'),
                                    ],
                                    'offer_analysis_id' => $checkTask->offer_analysis_id
                                ];
                            }
                            else if($checkTask->task_type_id == TaskType::ADDED_AUTHORIZER)
                            {
                                OfferAnalysisAuthorizer::where([['offer_analysis_id', $checkTask->offer_analysis_id], ['authorizer_id',  Auth::user()->id]])->update(['decision' => 1, 'decision_time' => now()]); // User has accepted request offer analysis authorizer

                                $notificationData = [
                                    'title' => [
                                        'en' => trans('messages.task_accepted_title', [], 'en'),
                                        'ar' => trans('messages.task_accepted_title', [], 'ar'),
                                    ],
                                    'body' => [
                                        'en' => trans('messages.task_accepted_body', ['user' => Auth::user()->first_name, 'task' => $taskNameEN, 'reference' => $reference, 'module' => $applicationModuleLabelEN], 'en'),
                                        'ar' => trans('messages.task_accepted_body', ['user' => Auth::user()->first_name, 'task' => $taskNameAR, 'reference' => $reference, 'module' => $applicationModuleLabelAR], 'ar'),
                                    ],
                                    'offer_analysis_id' => $checkTask->offer_analysis_id
                                ];
                            }
                            else if($checkTask->task_type_id == TaskType::ASSIGNED_EXECUTOR)
                            {
                                OfferAnalysis::where('id', $checkTask->offer_analysis_id)->update(['executor_confirmed_date' => now()]); // Add executor confirmed date

                                $notificationData = [
                                    'title' => [
                                        'en' => trans('messages.task_accepted_title', [], 'en'),
                                        'ar' => trans('messages.task_accepted_title', [], 'ar'),
                                    ],
                                    'body' => [
                                        'en' => trans('messages.task_accepted_body', ['user' => Auth::user()->first_name, 'task' => $taskNameEN, 'reference' => $reference, 'module' => $applicationModuleLabelEN], 'en'),
                                        'ar' => trans('messages.task_accepted_body', ['user' => Auth::user()->first_name, 'task' => $taskNameAR, 'reference' => $reference, 'module' => $applicationModuleLabelAR], 'ar'),
                                    ],
                                    'offer_analysis_id' => $checkTask->offer_analysis_id
                                ];
                            }
                        }
                        else
                        {
                            if($module == ApplicationModule::DPO)
                            {
                                // If module_status_id is 6 (Start Analyzing), then create Offer Analysis
                                if($checkTask->module_status_id == DirectPurchaseOrderStatus::START_ANALYZING)
                                {
                                    $offerAnalysis = [
                                        'organization_id' => $orgId,
                                        'purchase_request_id' => $checkTask->dpo->purchase_request_id,
                                        'direct_purchase_order_id' => $checkTask->dpo_id,
                                        'cost_centre_id' => $checkTask->dpo->cost_centre_id,
                                        'created_by' => Auth::id(),
                                    ];

                                    OfferAnalysis::create($offerAnalysis);
                                } 

                                // in case of accept task, add executor user decision and decision time
                                DPOStatusHistory::where([['organization_id',$orgId],['dpo_id',$checkTask->dpo_id],['dpo_status_id', $checkTask->module_status_id],['executer_id',Auth::id()]])->update(['decision' => DPOStatusHistory::EXECUTOR_APPROVED, 'decision_time' => now()]);
                            }
                            else if($module == ApplicationModule::PR)
                            {
                                // in case of accept task, add executor user decision and decision time
                                PurchaseRequestStatusHistory::where([['organization_id',$orgId],['purchase_request_id',$checkTask->purchase_request_id],['purchase_request_status_id', $checkTask->module_status_id],['executer_id',Auth::id()]])->update(['decision' => PurchaseRequestStatusHistory::APPROVED, 'decision_time' => now()]);
                            }
                            else if($module == ApplicationModule::FAR)
                            {
                                // in case of accept task, add executor user decision and decision time
                                farStatusHistory::where([['organization_id',$orgId],['far_id',$checkTask->far_id],['far_status_id', $checkTask->module_status_id],['executer_id',Auth::id()]])->update(['decision' => farStatusHistory::EXECUTOR_APPROVED, 'decision_time' => now()]);
                            }

                            $notificationData = [
                                'title' => [
                                    'en' => trans('messages.task_accepted_title', [], 'en'),
                                    'ar' => trans('messages.task_accepted_title', [], 'ar'),
                                ],
                                'body' => [
                                    'en' => trans('messages.task_accepted_body', ['user' => Auth::user()->first_name, 'task' => $taskNameEN, 'reference' => $reference, 'module' => $applicationModuleLabelEN], 'en'),
                                    'ar' => trans('messages.task_accepted_body', ['user' => Auth::user()->first_name, 'task' => $taskNameAR, 'reference' => $reference, 'module' => $applicationModuleLabelAR], 'ar'),
                                ],
                            ];
                        }
                    } 

               }
                elseif( $status == Task::REJECTED){
                    $checkTask->update(['status'=>2]); 
                    // going to delete the previous seletced status of the module and create the history for the receiver task performance. 
                    $statusId = $checkTask->module_status_id; //  form PR OR DPO OR OTHER
                    if($module == ApplicationModule::PR){
                        $checkPR = PurchaseRequest::find($checkTask->purchase_request_id);
                        if($checkPR){
                            // in case of reject task deleting the previous staus of that PR FOR this user
                            $checkHistory = PurchaseRequestStatusHistory::where([['organization_id',$orgId],['purchase_request_id',$checkTask->purchase_request_id],['purchase_request_status_id',$statusId],['executer_id',Auth::id()]])->first();
                            if($checkHistory){
                                $checkHistory->delete();
                            }
    
                        }
                    }elseif($module == ApplicationModule::DPO){ //deleting the DPO
                        $checkDPO = DirectPurchaseOrder::find($checkTask->dpo_id);
                        if($checkDPO){
                            // in case of reject task deleting the previous staus of that DPO FOR this user So that task could be assigned to another user
                            // dd('yes dpo');
                            $checkHistory = DPOStatusHistory::where([['organization_id',$orgId],['dpo_id',$checkTask->dpo_id],['dpo_status_id',$statusId],['executer_id',Auth::id()]])->first();
                            if($checkHistory){
                                $checkHistory->delete();
                            }
    
                        }
                    }
                    else{
    
                    }
                    //NOtification Scenario Rjected 
                    $receiver = User::find($checkTask->task_sender_id);
                    if($receiver){
                        $receiverLang = $receiver->languageType->code;
                        $type = 24;   // for  TASK_REJECTED

                        if($module == ApplicationModule::OA)
                        {
                            if($checkTask->task_type_id == TaskType::ADDED_COMMITTEE_MEMBER)
                            {
                                OfferAnalysisCommitteeMember::where([['offer_analysis_id', $checkTask->offer_analysis_id], ['user_id', Auth::user()->id]])->update(['decision' => 2, 'decision_time' => now()]); // User has rejected offer analysis request

                                $getRole = OfferAnalysisCommitteeMember::with('offerAnalysisCommitteeRole.labels')->where([['offer_analysis_id', $checkTask->offer_analysis_id], ['user_id', Auth::user()->id]])->first();
                                $roleEn = $getRole->offerAnalysisCommitteeRole->translate('en')->name;
                                $roleAr = $getRole->offerAnalysisCommitteeRole->translate('ar')->name;

                                $notificationData = [
                                    'title' => [
                                        'en' => trans('messages.task_rejected_offer_analysis_committee_member', [], 'en'),
                                        'ar' => trans('messages.task_rejected_offer_analysis_committee_member', [], 'ar'),
                                    ],
                                    'body' => [
                                        'en' => Auth::user()->first_name.' '.trans('messages.task_rejected_offer_analysis_committee_member_body', ['role' => $roleEn], 'en'),
                                        'ar' => Auth::user()->first_name.' '.trans('messages.task_rejected_offer_analysis_committee_member_body', ['role' => $roleAr], 'ar'),
                                    ],
                                    'offer_analysis_id' => $checkTask->offer_analysis_id
                                ];
                            }
                            else if($checkTask->task_type_id == TaskType::ADDED_AUTHORIZER)
                            {
                                OfferAnalysisAuthorizer::where([['offer_analysis_id', $checkTask->offer_analysis_id], ['authorizer_id',  Auth::user()->id]])->update(['decision' => 2, 'decision_time' => now()]); // User has rejected request offer analysis authorizer

                                $notificationData = [
                                    'title' => [
                                        'en' => trans('messages.task_rejected_title', [], 'en'),
                                        'ar' => trans('messages.task_rejected_title', [], 'ar'),
                                    ],
                                    'body' => [
                                        'en' => trans('messages.task_rejected_body', ['user' => Auth::user()->first_name, 'task' => $taskNameEN, 'reference' => $reference, 'module' => $applicationModuleLabelEN], 'en'),
                                        'ar' => trans('messages.task_rejected_body', ['user' => Auth::user()->first_name, 'task' => $taskNameAR, 'reference' => $reference, 'module' => $applicationModuleLabelAR], 'ar'),
                                    ]
                                ];
                            }
                            else if($checkTask->task_type_id == TaskType::ASSIGNED_EXECUTOR)
                            {
                                OfferAnalysis::where('id', $checkTask->offer_analysis_id)->update(['executor_id' => null, 'executor_assigned_by' => null]); // Remove executor

                                $notificationData = [
                                    'title' => [
                                        'en' => trans('messages.task_rejected_title', [], 'en'),
                                        'ar' => trans('messages.task_rejected_title', [], 'ar'),
                                    ],
                                    'body' => [
                                        'en' => trans('messages.task_rejected_body', ['user' => Auth::user()->first_name, 'task' => $taskNameEN, 'reference' => $reference, 'module' => $applicationModuleLabelEN], 'en'),
                                        'ar' => trans('messages.task_rejected_body', ['user' => Auth::user()->first_name, 'task' => $taskNameAR, 'reference' => $reference, 'module' => $applicationModuleLabelAR], 'ar'),
                                    ]
                                ];
                            }
                        }
                        else
                        {
                            $notificationData = [
                                'title' => [
                                    'en' => trans('messages.task_rejected_title', [], 'en'),
                                    'ar' => trans('messages.task_rejected_title', [], 'ar'),
                                ],
                                'body' => [
                                    'en' => trans('messages.task_rejected_body', ['user' => Auth::user()->first_name, 'task' => $taskNameEN, 'reference' => $reference, 'module' => $applicationModuleLabelEN], 'en'),
                                    'ar' => trans('messages.task_rejected_body', ['user' => Auth::user()->first_name, 'task' => $taskNameAR, 'reference' => $reference, 'module' => $applicationModuleLabelAR], 'ar'),
                                ]
                            ];
                        }
                    } 
                }else{
                    return  $response = ['status' => false ,'code' =>100  ,'message'=> 'invalid status id' ];
               }
               if($receiver){
                    if($receiver->notification_status == 1){
                        $device_id = $receiver->device_token;
                        if($device_id){
                            $title = $notificationData['title'][$receiverLang];
                            $body = $notificationData['body'][$receiverLang];
                            
                            //in helper file
                            push_notification($device_id,$title,$body,$type);
                        }
                    }
                        
                    Notification::send($receiver, new TaskNotification($type,$notificationData)); //saving to database
               }
               
               DB::commit();
               return $response = [ 'status' => true,'code' => 200,'message' => __('messages.success'), 'data'=>$checkTask ];
            }else{
                return  $response = ['status' => false ,'code' =>100  ,'message'=> 'invalid task id' ];
            }
        }catch(\Exception $e){
            return  $response = ['status' => false ,'code' =>500  ,'message'=> $e->getMessage() ];
        }
    }
    public function taskNotificationCount(){
        $tasksNotificationsCount = Auth::user()->notifications()
        ->where('type',"App\Notifications\TaskNotification")
        ->whereNull('read_at')
        ->count();
        $data = [
            'notification_count' =>$tasksNotificationsCount
        ];
        return $response = [ 'status' => true,'code' => 200,'message' => __('messages.success'), 'data'=>$data ];

    }
    public function taskNotificationMark(){
        try{
            $notifications =  Auth::user()->notifications()
            ->where('type',"App\Notifications\TaskNotification")
            ->get();
            foreach($notifications as $notification){
                $notification->markAsRead();
            }
            return $response = [ 'status' => true,'code' => 200,'message' => __('messages.success') ];
        }catch(\Exception $e){
            $response = ['status' => false ,'code' =>500 ,'message'=> $e->getMessage()];
            return response($response);
        }
    }

}
