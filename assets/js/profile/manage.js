lucide.createIcons();

function confirmHapus(event, title, type) {
    event.preventDefault();
    var link = event.currentTarget;
    var typeLabel = type === 'video' ? 'Video' : 'Musik';

    Swal.fire({
        title: 'Hapus ' + typeLabel + '?',
        html: '<div style="font-size:12px;color:var(--meel-text-secondary)">' +
            '"<strong style="color:var(--meel-text-heading)">' + title + '</strong>" akan dihapus dari database.<br>' +
            '<span style="color:var(--meel-text-muted);font-size:10px">File akan dibersihkan otomatis dalam 30 menit.</span>' +
            '</div>',
        icon: 'warning',
        iconColor: '#ef4444',
        showCancelButton: true,
        confirmButtonText: 'HAPUS',
        cancelButtonText: 'BATAL',
        background: 'var(--meel-surface)',
        color: 'var(--meel-text)',
        reverseButtons: true,
        customClass: {
            popup: 'border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl shadow-2xl',
            title: 'text-sm font-black uppercase tracking-wider pt-4 text-red-500',
            htmlContainer: 'mt-1 mb-4',
            confirmButton: 'bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer ml-2',
            cancelButton: 'bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl border border-white/10 cursor-pointer transition-all mr-2'
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = link.href;
        }
    });

    return false;
}
