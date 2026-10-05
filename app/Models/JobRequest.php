<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRequest extends Model
{
    use HasFactory;

    protected $table = 'job_requests';

    protected $fillable = [
        'job_no',
        'requestor',
        'requestor_name',
        'department',
        'contact',
        'date_request',
        'date_completion',
        'attachment',
        'request_types',
        'project_title',
        'purpose_objective',
        'event_datetime',
        'event_datetime_end',
        'event_not_applicable',
        'venue_location',
        'venue_not_applicable',
        'send_to',
        'send_to_not_applicable',
        'graphic_design_types',
        'graphic_design_other',
        'graphic_colors',
        'publication_methods',
        'publication_other',
        'graphic_quantity',
        'video_types',
        'video_type_other',
        'video_formats',
        'video_format_other',
        'video_quantity',
        'photographers_needed',
        'specific_shots',
        'coverage_formats',
        'coverage_format_other',
        'coverage_quantity',
        'request_details',
        'requestor_signature_date',
        'reviewed_by',
        'reviewed_date',
        'disapproved',
        'disapproved_reason',
        'approved_by',
        'approved_date',
        'initial_draft_date',
        'final_approval_date',
        'revision_required',
        'revision_count',
        'distribution_mode',
        'delivered_on_time',
        'objectives_met',
        'challenges_encountered',
        'challenges_details',
        'improvement_suggestions',
        'satisfaction_rating',
        'approval_status',
        'action_log',
    ];

    protected $casts = [
        'date_request' => 'date',
        'date_completion' => 'date',
        'event_datetime' => 'datetime',
        'event_datetime_end' => 'datetime',
        'event_not_applicable' => 'boolean',
        'venue_not_applicable' => 'boolean',
        'send_to_not_applicable' => 'boolean',
        'disapproved' => 'boolean',
        'graphic_quantity' => 'integer',
        'video_quantity' => 'integer',
        'photographers_needed' => 'integer',
        'coverage_quantity' => 'integer',
        'revision_count' => 'integer',
        'satisfaction_rating' => 'integer',
        'request_types' => 'array',
        'graphic_design_types' => 'array',
        'graphic_colors' => 'array',
        'publication_methods' => 'array',
        'video_types' => 'array',
        'video_formats' => 'array',
        'coverage_formats' => 'array',
    ];

    /**
     * Relationship: Request belongs to User (requestor)
     */
    public function requestorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor');
    }
}
