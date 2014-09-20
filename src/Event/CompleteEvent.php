<?php
namespace GuzzleHttp\Event;

/**
 * Event object emitted after a request has been completed.
 *
 * You may change the Response associated with the request using the
 * intercept() method of the event.
 */
class CompleteEvent extends AbstractTransferEvent {}
