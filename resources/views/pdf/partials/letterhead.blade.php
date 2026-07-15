{{-- Municipality letterhead with the document title. Expects $municipality, $title, $subtitle (optional). --}}
{{-- Laid out as a table: dompdf mis-stacks floated blocks. --}}
<div class="letterhead">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top;">
                <div class="muni-name">{{ $municipality->name }}</div>
                <div class="muni-contact">
                    @if ($municipality->address) {{ $municipality->address }} &middot; @endif
                    @if ($municipality->contact_phone) {{ $municipality->contact_phone }} &middot; @endif
                    {{ $municipality->contact_email }}
                </div>
            </td>
            <td style="vertical-align: top; text-align: right; white-space: nowrap;">
                <div class="doc-title">{{ $title }}</div>
                @if (! empty($subtitle))
                    <div class="doc-subtitle">{{ $subtitle }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>
