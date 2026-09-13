<x-mail::message>
# Enquiry from the InternTrack landing page

**From:** {{ $senderName }}
**Email:** {{ $senderEmail }}

{{-- Rendered as plain text, never as markdown: this is a stranger's input, and
     interpreting it would let a submission forge headings or links inside a
     message the coordinator is meant to read as quoted text. --}}
<x-mail::panel>
{{ $body }}
</x-mail::panel>

Reply directly to this message to answer {{ $senderName }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
