<?php

namespace Themosis\Core\Http\Events;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RequestHandled
{
    /**
     * @var Request
     */
    public $request;

    /**
     * @var Response
     */
    public $response;

    /**
     * RequestHandled constructor.
     *
     * @param  Request  $request
     * @param  Response  $response
     */
    public function __construct($request, $response)
    {
        $this->request = $request;
        $this->response = $response;
    }
}
