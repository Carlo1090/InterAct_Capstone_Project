<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\ServesLocationMaps;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coordinator's side of the company-location picker, for the GROUP
 * Student Information Sheet's sketch box. Same map, same search and same
 * cache as the student's picker (ServesLocationMaps); only the preview's
 * shape differs, because the group sheet's box does.
 */
class CoordinatorLocationController extends Controller
{
    use ServesLocationMaps;

    /**
     * The group sheet's sketch box is 518.4 x 189.65pt (2.733:1) — a different
     * shape from the individual sheet's 90mm box — so its preview is too.
     * MUST track BuildsGroupInfoSheetPdf::GROUP_SKETCH_* and the picker's
     * printRatio on the coordinator page.
     */
    public const GROUP_PREVIEW_WIDTH = 640;

    public const GROUP_PREVIEW_HEIGHT = 234;

    public function options(): JsonResponse
    {
        return $this->locationOptionsResponse();
    }

    public function preview(Request $request): Response
    {
        return $this->locationPreviewResponse($request, self::GROUP_PREVIEW_WIDTH, self::GROUP_PREVIEW_HEIGHT);
    }

    public function search(Request $request): JsonResponse
    {
        return $this->locationSearchResponse($request);
    }
}
