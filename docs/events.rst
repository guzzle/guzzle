============
Event System
============

Requests emit lifecycle events when they are transferred.

- **before**: Emitted before a request is sent. The event is a
  ``GuzzleHttp\Event\BeforeEvent``.
- **headers**: Emitted after the headers of a response have been received. This
  event is emitted before any of the response body has been downloaded. The
  event is a ``GuzzleHttp\Event\HeadersEvent``.
- **complete**: Emitted after an event completes. The event is a
  ``GuzzleHttp\Event\CompleteEvent``.
- **error**: Emitted when a request fails-- whether it's from a networking
  error or an HTTP protocol error. The event emitted is a
  ``GuzzleHttp\Event\ErrorEvent``.

A client object has a ``GuzzleHttp\Common\EventEmitter`` object that can be
used to add event *listeners* and event *subscribers* to all requests created
by the client.

- **event listeners** are callable functions that are registered on an event
  emitter for specific events.
- **event subscribers** are classes that tell an event emitter what methods to
  listen to and what functions on the class to invoke when the event is
  triggered. Event subscribers register event listeners with an event emitter.
  They should be used when created more complex event based logic to
  applications (i.e., cookie handling is implemented using an event subscriber
  because it's easier to share a subscriber than an anonymous function and
  because handling cookies is a complex process).

before Event
============

headers Event
=============

complete Event
==============

error event
===========
