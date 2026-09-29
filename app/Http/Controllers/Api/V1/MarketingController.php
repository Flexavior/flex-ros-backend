<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingChannel;
use App\Models\MarketingPost;
use App\Models\Role;
use Illuminate\Http\Request;

class MarketingController extends Controller
{
    // ---- Channels ----
    public function channels()
    {
        return response()->json(MarketingChannel::orderBy('name')->get());
    }

    public function storeChannel(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:marketing_channels,code',
            'type' => 'required|in:social,messaging,search,email,offline,other',
        ]);

        return response()->json(MarketingChannel::create($data), 201);
    }

    // ---- Campaigns (plan -> approval -> publish) ----
    public function campaigns(Request $request)
    {
        $query = MarketingCampaign::with(['channel:id,name,code', 'owner:id,name', 'productService:id,name'])
            ->orderByDesc('created_at');

        $user = $request->user();
        if (!$user->isOrgLevel() && !$user->canApprove()) {
            $query->where('owner_id', $user->id);
        }

        return response()->json($query->get());
    }

    public function storeCampaign(Request $request)
    {
        $data = $request->validate([
            'channel_id' => 'required|exists:marketing_channels,id',
            'product_service_id' => 'nullable|exists:products_services,id',
            'name' => 'required|string|max:255',
            'objective' => 'nullable|string',
            'budget' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $campaign = MarketingCampaign::create($data + [
            'owner_id' => $request->user()->id,
            'approval_status' => 'draft',
        ]);

        return response()->json($campaign, 201);
    }

    public function submitCampaign(Request $request, MarketingCampaign $campaign)
    {
        abort_unless($campaign->owner_id === $request->user()->id || $request->user()->canApprove(), 403);
        abort_if($campaign->approval_status === 'approved', 422, 'Campaign already approved.');

        $campaign->update(['approval_status' => 'pending']);

        return response()->json($campaign);
    }

    public function decideCampaign(Request $request, MarketingCampaign $campaign)
    {
        abort_unless($request->user()->canApprove(), 403, 'Only CEO / Senior Management / Supervisor can approve.');

        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'rejection_reason' => 'required_if:decision,rejected|nullable|string',
        ]);

        $campaign->update([
            'approval_status' => $data['decision'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $data['rejection_reason'] ?? null,
        ]);

        return response()->json($campaign);
    }

    // ---- Posts (posting schedule) ----
    public function posts(Request $request)
    {
        $query = MarketingPost::with(['campaign:id,name,channel_id,approval_status'])
            ->orderBy('scheduled_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    public function storePost(Request $request)
    {
        $data = $request->validate([
            'campaign_id' => 'required|exists:marketing_campaigns,id',
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
            'media_url' => 'nullable|string',
        ]);

        $campaign = MarketingCampaign::findOrFail($data['campaign_id']);
        abort_unless($campaign->approval_status === 'approved', 422, 'Campaign must be approved before scheduling posts.');

        return response()->json(MarketingPost::create($data + ['status' => 'scheduled']), 201);
    }

    public function updatePost(Request $request, MarketingPost $post)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
            'status' => 'sometimes|in:draft,scheduled,published,cancelled',
            'reach' => 'sometimes|integer|min:0',
            'leads_generated' => 'sometimes|integer|min:0',
        ]);

        if (isset($data['status']) && $data['status'] === 'published') {
            $data['published_at'] = now();
        }

        $post->update($data);

        return response()->json($post);
    }
}
