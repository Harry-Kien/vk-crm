{{-- Bản văn bản thuần. `{!! !!}` có chủ ý: xem docblock của emails.layout-text.
     Danh sách: mỗi đầu mục một dòng, KẾT THÚC bằng một lệnh in (Blade giữ dòng mới sau lệnh in
     nhưng nuốt dòng mới sau `@endif`) — không dấu cách thừa cuối dòng; đúng một dòng trống trước
     và sau danh sách. Chú thích này nằm ở đầu tệp, ngoài `@section`: một chú thích Blade trong
     `content` để lại dòng mới của nó thành một dòng trống thừa. --}}
@extends('emails.layout-text')

@section('content')
{!! __('portal.email.missing_documents.greeting', ['name' => $name]) !!}

{!! __('portal.email.missing_documents.line', ['code' => $matterCode]) !!}

@foreach ($lines as $line)
- {!! $line['name'] !!}{!! $line['rejected'] ? ' — '.__('portal.email.missing_documents.rejected') : '' !!}{!! $line['reason'] ? ' ('.__('portal.email.missing_documents.rejected_reason', ['reason' => $line['reason']]).')' : '' !!}
@endforeach

{!! __('portal.email.missing_documents.open') !!} {!! $portalUrl !!}

{!! __('portal.email.missing_documents.help', ['phone' => $hotline]) !!}

{!! __('portal.email.missing_documents.salutation', ['office' => $office]) !!}
@endsection
