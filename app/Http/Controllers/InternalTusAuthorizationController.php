<?php

namespace App\Http\Controllers;

use App\Support\Media\Exceptions\UploadTransportException;
use App\Support\Media\TusRequestAuthorizer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalTusAuthorizationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, TusRequestAuthorizer $authorizer): Response
    {
        try {
            $authorizer->authorize($request);
        } catch (UploadTransportException $exception) {
            $status = $exception->status === 401 ? 401 : 403;

            return response('', $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer'] : []);
        }

        return response()->noContent();
    }
}
