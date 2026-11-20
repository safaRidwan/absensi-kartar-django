import math
from django.shortcuts import render, redirect, get_object_or_404
from django.contrib.auth import authenticate, login, logout
from django.contrib.auth.decorators import login_required
from django.contrib import messages
from django.core.paginator import Paginator
from django.db.models import Case, When, Value, IntegerField
from django.utils import timezone
from .models import User, Event, Attendance
from datetime import timedelta
from django.http import HttpResponse
import openpyxl
from openpyxl.styles import Font, Alignment, PatternFill
import urllib.request
import urllib.parse
import json
from django.conf import settings

def kirim_notif_telegram(event):
    token = getattr(settings, 'TELEGRAM_BOT_TOKEN', '')
    chat_id = getattr(settings, 'TELEGRAM_CHAT_ID', '')
    if not token or not chat_id or not event.is_active:
        return

    mulai = timezone.localtime(event.start_time).strftime('%d %b %Y, %H:%M WIB')
    selesai = timezone.localtime(event.end_time).strftime('%H:%M WIB')
    maps_url = f"https://maps.google.com/?q={event.lat},{event.lon}"

    pesan = (
        f"📢 *KEGIATAN BARU KARANG TARUNA*\n\n"
        f"📌 *Acara:* {event.title}\n"
        f"🕒 *Waktu:* {mulai} - {selesai}\n"
        f"📍 *Radius:* {event.tolerance_meters} meter\n"
        f"🗺 *Lokasi:* [Lihat Peta]({maps_url})\n\n"
        f"Silakan lakukan absensi masuk tepat waktu melalui web absensi."
    )

    url = f"https://api.telegram.org/bot{token}/sendMessage"
    data = urllib.parse.urlencode({
        'chat_id': chat_id,
        'text': pesan,
        'parse_mode': 'Markdown',
        'disable_web_page_preview': True,
    }).encode('utf-8')

    try:
        req = urllib.request.Request(url, data=data)
        urllib.request.urlopen(req, timeout=5)
    except Exception:
        pass

def bersihkan_foto_lama(hari=30):
    batas_waktu = timezone.now() - timedelta(days=hari)
    foto_lama = Attendance.objects.filter(
        created_at__lt=batas_waktu
    ).exclude(selfie='').exclude(selfie__isnull=True)[:20]

    for item in foto_lama:
        item.selfie.delete(save=False)
        item.selfie = None
        item.save(update_fields=['selfie'])

def hitung_jarak_meter(lat1, lon1, lat2, lon2):
    R = 6371000
    phi1, phi2 = math.radians(float(lat1)), math.radians(float(lat2))
    dphi = math.radians(float(lat2) - float(lat1))
    dlambda = math.radians(float(lon2) - float(lon1))
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return 2 * R * math.atan2(math.sqrt(a), math.sqrt(1 - a))

def is_admin_or_pengurus(user):
    return user.is_authenticated and (user.is_superuser or user.role in ['admin', 'pengurus'])

def login_view(request):
    if request.user.is_authenticated:
        return redirect('admin_dashboard' if is_admin_or_pengurus(request.user) else 'member_dashboard')

    if request.method == 'POST':
        username = request.POST.get('username', '').strip()
        password = request.POST.get('password', '')
        user = authenticate(request, username=username, password=password)
        if user is not None:
            login(request, user)
            return redirect('admin_dashboard' if is_admin_or_pengurus(user) else 'member_dashboard')
        messages.error(request, 'Username atau password salah.')

    return render(request, 'absensi/login.html')

def logout_view(request):
    logout(request)
    messages.success(request, 'Anda berhasil logout.')
    return redirect('login')

@login_required
def attendance_logs(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    bersihkan_foto_lama(hari=30)

    all_events = Event.objects.order_by('-id')
    selected_event_id = request.GET.get('event_id')
    filter_rt = request.GET.get('rt', '')

    if selected_event_id:
        selected_event = get_object_or_404(Event, id=selected_event_id)
    else:
        selected_event = all_events.first()

    log_map = {}
    wa_report_text = ''

    if selected_event:
        logs = Attendance.objects.filter(event=selected_event).select_related('user').order_by('created_at')
        if filter_rt:
            logs = logs.filter(user__rt=filter_rt)

        for log in logs:
            uid = log.user.id
            if uid not in log_map:
                log_map[uid] = {
                    'name': log.user.name or log.user.username,
                    'rt': log.user.rt or '-',
                    'in_time': '-',
                    'out_time': '-',
                    'selfie_url': '',
                    'notes': '',
                }
            waktu = timezone.localtime(log.created_at).strftime('%H:%M')
            if log.type == 'in':
                log_map[uid]['in_time'] = waktu
                if log.selfie:
                    log_map[uid]['selfie_url'] = log.selfie.url
            elif log.type == 'out':
                log_map[uid]['out_time'] = waktu
            elif log.type == 'izin':
                log_map[uid]['in_time'] = 'IZIN'
                log_map[uid]['out_time'] = 'IZIN'
                log_map[uid]['notes'] = log.notes or ''
                if log.selfie:
                    log_map[uid]['selfie_url'] = log.selfie.url

        # Susun Teks Rekap WhatsApp
        users_qs = User.objects.all().order_by('rt', 'name', 'username')
        if filter_rt:
            users_qs = users_qs.filter(rt=filter_rt)

        hadir_list, izin_list, alpa_list = [], [], []
        for u in users_qs:
            nama_u = f"{u.name or u.username} (RT {u.rt or '-'})"
            info = log_map.get(u.id)
            if info and info['in_time'] == 'IZIN':
                ket = f" - {info['notes']}" if info['notes'] else ""
                izin_list.append(f"{nama_u}{ket}")
            elif info:
                hadir_list.append(f"{nama_u} [{info['in_time']}]")
            else:
                alpa_list.append(nama_u)

        tgl_str = timezone.localtime(selected_event.start_time).strftime('%d %b %Y')
        rt_label = f" (RT {filter_rt})" if filter_rt else ""
        lines = [
            f"*REKAP ABSENSI KARANG TARUNA*{rt_label}",
            f"Kegiatan: *{selected_event.title}*",
            f"Tanggal: {tgl_str}",
            "",
            f"✅ *HADIR ({len(hadir_list)}):*",
        ]
        lines += [f"{i}. {n}" for i, n in enumerate(hadir_list, 1)] or ["-"]
        lines += ["", f"📩 *IZIN ({len(izin_list)}):*"]
        lines += [f"{i}. {n}" for i, n in enumerate(izin_list, 1)] or ["-"]
        lines += ["", f"❌ *BELUM HADIR ({len(alpa_list)}):*"]
        lines += [f"{i}. {n}" for i, n in enumerate(alpa_list, 1)] or ["-"]
        wa_report_text = "\n".join(lines)

    return render(request, 'absensi/logs.html', {
        'all_events': all_events,
        'selected_event': selected_event,
        'filter_rt': filter_rt,
        'logs': log_map.values(),
        'wa_report_text': wa_report_text,
    })

@login_required
def download_members_template(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Template Anggota"

    headers = ['Nama Lengkap', 'Username', 'Password', 'RT', 'Role']
    ws.append(headers)

    header_fill = PatternFill(start_color="696CFF", end_color="696CFF", fill_type="solid")
    for col_num in range(1, 6):
        cell = ws.cell(row=1, column=col_num)
        cell.font = Font(bold=True, color="FFFFFF")
        cell.fill = header_fill

    # Contoh baris pengisian
    ws.append(['Budi Santoso', 'budi05', '123456', '05', 'member'])
    ws.append(['Siti Aminah', 'siti06', '123456', '06', 'pengurus'])

    for col_letter, width in [('A', 28), ('B', 18), ('C', 15), ('D', 10), ('E', 14)]:
        ws.column_dimensions[col_letter].width = width

    response = HttpResponse(
        content_type='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    )
    response['Content-Disposition'] = 'attachment; filename="Template_Import_Anggota.xlsx"'
    wb.save(response)
    return response

@login_required
def import_members_excel(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    if request.method == 'POST' and request.FILES.get('excel_file'):
        excel_file = request.FILES['excel_file']
        try:
            wb = openpyxl.load_workbook(excel_file)
            ws = wb.active
            berhasil, dilewati = 0, 0

            for row in ws.iter_rows(min_row=2, values_only=True):
                if not row or not row[1]:
                    continue
                nama = str(row[0] or '').strip()
                username = str(row[1]).strip()
                password = str(row[2] or '123456').strip()
                rt_raw = str(row[3] or '').strip().zfill(2) if row[3] else ''
                role = str(row[4] or 'member').strip().lower()
                if role not in ['admin', 'pengurus', 'member']:
                    role = 'member'

                if User.objects.filter(username=username).exists():
                    dilewati += 1
                    continue

                User.objects.create_user(
                    username=username,
                    password=password,
                    name=nama or username,
                    rt=rt_raw,
                    role=role,
                )
                berhasil += 1

            messages.success(request, f"Impor selesai: {berhasil} anggota ditambahkan, {dilewati} username duplikat dilewati.")
        except Exception as e:
            messages.error(request, f"Gagal membaca file Excel: {e}")

    return redirect('members_list')

@login_required
def export_attendance_excel(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    event_id = request.GET.get('event_id')
    filter_rt = request.GET.get('rt', '')
    event = get_object_or_404(Event, id=event_id)

    # Ambil semua pengguna (urut RT lalu Nama)
    users_qs = User.objects.all().order_by('rt', 'name', 'username')
    if filter_rt:
        users_qs = users_qs.filter(rt=filter_rt)

    # Ambil data absensi pada kegiatan ini
    attendances = Attendance.objects.filter(event=event)
    status_map = {}
    for att in attendances:
        if att.type == 'izin':
            status_map[att.user_id] = 'Izin'
        elif att.type in ['in', 'out'] and status_map.get(att.user_id) != 'Izin':
            status_map[att.user_id] = 'Hadir'

    # Buat file Excel
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Rekap Kehadiran"

    # Judul Kegiatan
    ws.merge_cells('A1:D1')
    ws['A1'] = f"REKAP KEHADIRAN: {event.title.upper()}"
    ws['A1'].font = Font(bold=True, size=12)
    ws['A1'].alignment = Alignment(horizontal='center')

    ws.merge_cells('A2:D2')
    tanggal_str = timezone.localtime(event.start_time).strftime('%d %B %Y, %H:%M WIB')
    ws['A2'] = f"Waktu: {tanggal_str}" + (f" | Filter: RT {filter_rt}" if filter_rt else "")
    ws['A2'].alignment = Alignment(horizontal='center')

    # Header Tabel
    headers = ['No', 'Nama Anggota', 'RT', 'Status Kehadiran']
    ws.append([])
    ws.append(headers)

    header_fill = PatternFill(start_color="696CFF", end_color="696CFF", fill_type="solid")
    for col_num in range(1, 5):
        cell = ws.cell(row=4, column=col_num)
        cell.font = Font(bold=True, color="FFFFFF")
        cell.fill = header_fill
        cell.alignment = Alignment(horizontal='center')

    # Isi Data Anggota
    for idx, u in enumerate(users_qs, start=1):
        status = status_map.get(u.id, 'Tidak Hadir')
        nama_lengkap = u.name or u.username
        rt_str = f"RT {u.rt}" if u.rt else "-"
        ws.append([idx, nama_lengkap, rt_str, status])

        row_idx = idx + 4
        ws.cell(row=row_idx, column=1).alignment = Alignment(horizontal='center')
        ws.cell(row=row_idx, column=3).alignment = Alignment(horizontal='center')
        status_cell = ws.cell(row=row_idx, column=4)
        status_cell.alignment = Alignment(horizontal='center')

        if status == 'Hadir':
            status_cell.font = Font(bold=True, color="28A745")
        elif status == 'Izin':
            status_cell.font = Font(bold=True, color="FF9F43")
        else:
            status_cell.font = Font(color="EA5455")

    # Atur lebar kolom otomatis
    ws.column_dimensions['A'].width = 6
    ws.column_dimensions['B'].width = 32
    ws.column_dimensions['C'].width = 10
    ws.column_dimensions['D'].width = 20

    nama_file = f"Absensi_{event.title.replace(' ', '_')}.xlsx"
    response = HttpResponse(
        content_type='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    )
    response['Content-Disposition'] = f'attachment; filename="{nama_file}"'
    wb.save(response)
    return response

@login_required
def admin_dashboard(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    now = timezone.now()
    today = timezone.localdate()

    total_members = User.objects.count()
    ongoing_events = Event.objects.filter(is_active=True, start_time__lte=now, end_time__gte=now).count()
    finished_events = Event.objects.filter(end_time__lt=now).count()
    today_absence = Attendance.objects.filter(created_at__date=today).values('user').distinct().count()

    events = Event.objects.order_by('-id')[:10]

    context = {
        'total_members': total_members,
        'ongoing_events': ongoing_events,
        'finished_events': finished_events,
        'today_absence': today_absence,
        'events': events,
        'now': now,
    }
    return render(request, 'absensi/index.html', context)

@login_required
def attendance_logs(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    bersihkan_foto_lama(hari=30)

    all_events = Event.objects.order_by('-id')
    selected_event_id = request.GET.get('event_id')
    filter_rt = request.GET.get('rt', '')

    if selected_event_id:
        selected_event = get_object_or_404(Event, id=selected_event_id)
    else:
        selected_event = all_events.first()

    log_map = {}
    wa_report_text = ''

    if selected_event:
        logs = Attendance.objects.filter(event=selected_event).select_related('user').order_by('created_at')
        if filter_rt:
            logs = logs.filter(user__rt=filter_rt)

        for log in logs:
            uid = log.user.id
            if uid not in log_map:
                log_map[uid] = {
                    'name': log.user.name or log.user.username,
                    'rt': log.user.rt or '-',
                    'in_time': '-',
                    'out_time': '-',
                    'selfie_url': '',
                    'notes': '',
                }
            waktu = timezone.localtime(log.created_at).strftime('%H:%M')
            if log.type == 'in':
                log_map[uid]['in_time'] = waktu
                if log.selfie:
                    log_map[uid]['selfie_url'] = log.selfie.url
            elif log.type == 'out':
                log_map[uid]['out_time'] = waktu
            elif log.type == 'izin':
                log_map[uid]['in_time'] = 'IZIN'
                log_map[uid]['out_time'] = 'IZIN'
                log_map[uid]['notes'] = log.notes or ''
                if log.selfie:
                    log_map[uid]['selfie_url'] = log.selfie.url

        # Susun Teks Rekap WhatsApp
        users_qs = User.objects.all().order_by('rt', 'name', 'username')
        if filter_rt:
            users_qs = users_qs.filter(rt=filter_rt)

        hadir_list, izin_list, alpa_list = [], [], []
        for u in users_qs:
            nama_u = f"{u.name or u.username} (RT {u.rt or '-'})"
            info = log_map.get(u.id)
            if info and info['in_time'] == 'IZIN':
                ket = f" - {info['notes']}" if info['notes'] else ""
                izin_list.append(f"{nama_u}{ket}")
            elif info:
                hadir_list.append(f"{nama_u} [{info['in_time']}]")
            else:
                alpa_list.append(nama_u)

        tgl_str = timezone.localtime(selected_event.start_time).strftime('%d %b %Y')
        rt_label = f" (RT {filter_rt})" if filter_rt else ""
        lines = [
            f"*REKAP ABSENSI KARANG TARUNA*{rt_label}",
            f"Kegiatan: *{selected_event.title}*",
            f"Tanggal: {tgl_str}",
            "",
            f"✅ *HADIR ({len(hadir_list)}):*",
        ]
        lines += [f"{i}. {n}" for i, n in enumerate(hadir_list, 1)] or ["-"]
        lines += ["", f"📩 *IZIN ({len(izin_list)}):*"]
        lines += [f"{i}. {n}" for i, n in enumerate(izin_list, 1)] or ["-"]
        lines += ["", f"❌ *BELUM HADIR ({len(alpa_list)}):*"]
        lines += [f"{i}. {n}" for i, n in enumerate(alpa_list, 1)] or ["-"]
        wa_report_text = "\n".join(lines)

    return render(request, 'absensi/logs.html', {
        'all_events': all_events,
        'selected_event': selected_event,
        'filter_rt': filter_rt,
        'logs': log_map.values(),
        'wa_report_text': wa_report_text,
    })

@login_required
def event_create(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    if request.method == 'POST':
        ev = Event.objects.create(
            title=request.POST.get('title'),
            start_time=request.POST.get('start_time'),
            end_time=request.POST.get('end_time'),
            lat=request.POST.get('lat', '').replace(',', '.'),
            lon=request.POST.get('lon', '').replace(',', '.'),
            tolerance_meters=request.POST.get('tolerance', 50),
            is_active='is_active' in request.POST,
        )
        kirim_notif_telegram(ev)
        messages.success(request, 'Kegiatan baru berhasil dibuat.')
        return redirect('admin_dashboard')

    return render(request, 'absensi/event_create.html')

@login_required
def event_edit(request, event_id):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    event = get_object_or_404(Event, id=event_id)
    if request.method == 'POST':
        event.title = request.POST.get('title')
        event.start_time = request.POST.get('start_time')
        event.end_time = request.POST.get('end_time')
        event.lat = request.POST.get('lat', '').replace(',', '.')
        event.lon = request.POST.get('lon', '').replace(',', '.')
        event.tolerance_meters = request.POST.get('tolerance', 50)
        event.is_active = 'is_active' in request.POST
        event.save()
        messages.success(request, 'Kegiatan berhasil diperbarui.')
        return redirect('admin_dashboard')

    return render(request, 'absensi/event_edit.html', {'event': event})

@login_required
def event_delete(request, event_id):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    event = get_object_or_404(Event, id=event_id)
    event.delete()
    messages.success(request, 'Kegiatan berhasil dihapus.')
    return redirect('admin_dashboard')

@login_required
def members_list(request):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    filter_rt = request.GET.get('rt', '')
    current_role = 'admin' if request.user.is_superuser else request.user.role

    if request.method == 'POST':
        action = request.POST.get('action')
        if action == 'add':
            name = request.POST.get('name')
            username = request.POST.get('username').strip()
            password = request.POST.get('password')
            role = request.POST.get('role', 'member')
            rt = request.POST.get('rt') or None

            if current_role == 'pengurus' and role == 'admin':
                messages.error(request, 'Pengurus tidak memiliki izin menambah Admin.')
            elif User.objects.filter(username=username).exists():
                messages.error(request, f"Username '{username}' sudah digunakan!")
            else:
                user = User.objects.create_user(username=username, password=password, name=name, role=role, rt=rt)
                if role == 'admin':
                    user.is_staff = True
                    user.save()
                messages.success(request, 'Anggota berhasil ditambahkan.')
            return redirect('members_list')

        elif action == 'edit':
            target = get_object_or_404(User, id=request.POST.get('user_id'))
            if current_role == 'pengurus' and target.role == 'admin':
                messages.error(request, 'Pengurus dilarang mengubah data Admin.')
            else:
                new_username = request.POST.get('username').strip()
                if User.objects.filter(username=new_username).exclude(id=target.id).exists():
                    messages.error(request, f"Username '{new_username}' sudah dipakai pengguna lain!")
                else:
                    target.name = request.POST.get('name')
                    target.username = new_username
                    target.rt = request.POST.get('rt') or None
                    if current_role == 'admin':
                        target.role = request.POST.get('role', target.role)
                    new_pass = request.POST.get('password')
                    if new_pass:
                        target.set_password(new_pass)
                    target.save()
                    messages.success(request, 'Data pengguna diperbarui.')
            return redirect('members_list')

    qs = User.objects.all()
    if filter_rt:
        qs = qs.filter(rt=filter_rt)

    qs = qs.annotate(
        role_order=Case(
            When(role='admin', then=Value(1)),
            When(role='pengurus', then=Value(2)),
            default=Value(3),
            output_field=IntegerField(),
        )
    ).order_by('role_order', 'name')

    paginator = Paginator(qs, 10)
    page_obj = paginator.get_page(request.GET.get('hal', 1))

    return render(request, 'absensi/members.html', {
        'page_obj': page_obj,
        'filter_rt': filter_rt,
        'current_role': current_role,
    })

@login_required
def member_delete(request, user_id):
    if not is_admin_or_pengurus(request.user):
        return redirect('member_dashboard')

    target = get_object_or_404(User, id=user_id)
    current_role = 'admin' if request.user.is_superuser else request.user.role

    if target.id == request.user.id:
        messages.error(request, 'Tidak dapat menghapus akun sendiri.')
    elif current_role == 'pengurus' and target.role == 'admin':
        messages.error(request, 'Pengurus tidak diizinkan menghapus Admin.')
    else:
        target.delete()
        messages.success(request, 'Pengguna berhasil dihapus.')
    return redirect('members_list')

@login_required
def member_dashboard(request):
    now = timezone.now()
    event = Event.objects.filter(is_active=True).order_by('-start_time').first()

    if request.method == 'POST':
        if 'update_profile' in request.POST:
            new_username = request.POST.get('username').strip()
            if User.objects.filter(username=new_username).exclude(id=request.user.id).exists():
                messages.error(request, 'Username sudah digunakan pengguna lain!')
            else:
                request.user.name = request.POST.get('name')
                request.user.username = new_username
                new_pass = request.POST.get('password')
                if new_pass:
                    request.user.set_password(new_pass)
                request.user.save()
                if new_pass:
                    login(request, request.user)
                messages.success(request, 'Profil berhasil disimpan!')
            return redirect('member_dashboard')

        if event:
            absen_type = request.POST.get('type')
            lat = request.POST.get('latitude')
            lon = request.POST.get('longitude')
            notes = request.POST.get('notes', '')
            selfie = request.FILES.get('selfie')

            # Validasi jeda 5 menit di backend agar tidak bisa ditembus
            if absen_type == 'out':
                cek_in = Attendance.objects.filter(user=request.user, event=event, type='in').first()
                if not cek_in:
                    messages.error(request, 'Anda belum melakukan absen masuk!')
                    return redirect('member_dashboard')
                waktu_buka_pulang = cek_in.created_at + timedelta(minutes=5)
                if now < waktu_buka_pulang:
                    sisa_menit = math.ceil((waktu_buka_pulang - now).total_seconds() / 60)
                    messages.error(request, f"Absen pulang baru bisa dilakukan {sisa_menit} menit lagi.")
                    return redirect('member_dashboard')

            jarak = hitung_jarak_meter(lat, lon, event.lat, event.lon)
            if absen_type != 'izin' and jarak > event.tolerance_meters:
                messages.error(request, f"Lokasi di luar radius! Jarak Anda {int(jarak)}m (Maks {event.tolerance_meters}m).")
                return redirect('member_dashboard')

            Attendance.objects.create(
                user=request.user,
                event=event,
                type=absen_type,
                selfie=selfie,
                latitude=lat,
                longitude=lon,
                status='valid',
                notes=notes,
            )
            messages.success(request, f"Absensi ({absen_type.upper()}) berhasil dicatat!")
            return redirect('member_dashboard')

    status_absensi = 'belum'
    btn_label = 'KIRIM ABSEN'
    event_not_started = False
    event_expired = False
    data_in = None
    data_izin = None
    checkout_locked = False
    checkout_unlock_ts = 0

    if event:
        data_in = Attendance.objects.filter(user=request.user, event=event, type='in').first()
        data_out = Attendance.objects.filter(user=request.user, event=event, type='out').first()
        data_izin = Attendance.objects.filter(user=request.user, event=event, type='izin').first()

        if data_izin:
            status_absensi = 'selesai'
        elif not data_in:
            status_absensi = 'siap_masuk'
            btn_label = 'KIRIM ABSEN MASUK'
        elif data_in and not data_out:
            status_absensi = 'siap_pulang'
            btn_label = 'KIRIM ABSEN PULANG'
            waktu_buka_pulang = data_in.created_at + timedelta(minutes=5)
            if now < waktu_buka_pulang:
                checkout_locked = True
                checkout_unlock_ts = int(waktu_buka_pulang.timestamp() * 1000)
        else:
            status_absensi = 'selesai'

        if now < event.start_time:
            event_not_started = True
        elif status_absensi != 'selesai' and now > event.end_time:
            event_expired = True

    raw_history = Attendance.objects.filter(user=request.user).select_related('event').order_by('-event__start_time', 'created_at')
    history_map = {}
    for row in raw_history:
        eid = row.event.id
        if eid not in history_map:
            history_map[eid] = {'title': row.event.title, 'in': '-', 'out': '-', 'status': 'Hadir'}
        waktu = timezone.localtime(row.created_at).strftime('%H:%M')
        if row.type == 'in':
            history_map[eid]['in'] = waktu
        elif row.type == 'out':
            history_map[eid]['out'] = waktu
        elif row.type == 'izin':
            history_map[eid]['in'] = 'IZIN'
            history_map[eid]['out'] = 'IZIN'
            history_map[eid]['status'] = f"Izin: {row.notes}"

    paginator = Paginator(list(history_map.values()), 10)
    history_page = paginator.get_page(request.GET.get('hal', 1))

    return render(request, 'absensi/member.html', {
        'event': event,
        'status_absensi': status_absensi,
        'btn_label': btn_label,
        'event_not_started': event_not_started,
        'event_expired': event_expired,
        'data_in': data_in,
        'data_izin': data_izin,
        'checkout_locked': checkout_locked,
        'checkout_unlock_ts': checkout_unlock_ts,
        'history_page': history_page,
    })
    now = timezone.now()
    event = Event.objects.filter(is_active=True).order_by('-start_time').first()

    if request.method == 'POST':
        if 'update_profile' in request.POST:
            new_username = request.POST.get('username').strip()
            if User.objects.filter(username=new_username).exclude(id=request.user.id).exists():
                messages.error(request, 'Username sudah digunakan pengguna lain!')
            else:
                request.user.name = request.POST.get('name')
                request.user.username = new_username
                new_pass = request.POST.get('password')
                if new_pass:
                    request.user.set_password(new_pass)
                request.user.save()
                if new_pass:
                    login(request, request.user)
                messages.success(request, 'Profil berhasil disimpan!')
            return redirect('member_dashboard')

        if event:
            absen_type = request.POST.get('type')
            lat = request.POST.get('latitude')
            lon = request.POST.get('longitude')
            notes = request.POST.get('notes', '')
            selfie = request.FILES.get('selfie')

            jarak = hitung_jarak_meter(lat, lon, event.lat, event.lon)
            if absen_type != 'izin' and jarak > event.tolerance_meters:
                messages.error(request, f"Lokasi di luar radius! Jarak Anda {int(jarak)}m (Maks {event.tolerance_meters}m).")
                return redirect('member_dashboard')

            Attendance.objects.create(
                user=request.user,
                event=event,
                type=absen_type,
                selfie=selfie,
                latitude=lat,
                longitude=lon,
                status='valid',
                notes=notes,
            )
            messages.success(request, f"Absensi ({absen_type.upper()}) berhasil dicatat!")
            return redirect('member_dashboard')

    status_absensi = 'belum'
    btn_label = 'KIRIM ABSEN'
    event_not_started = False
    event_expired = False
    data_in = None
    data_izin = None

    if event:
        data_in = Attendance.objects.filter(user=request.user, event=event, type='in').first()
        data_out = Attendance.objects.filter(user=request.user, event=event, type='out').first()
        data_izin = Attendance.objects.filter(user=request.user, event=event, type='izin').first()

        if data_izin:
            status_absensi = 'selesai'
        elif not data_in:
            status_absensi = 'siap_masuk'
            btn_label = 'KIRIM ABSEN MASUK'
        elif data_in and not data_out:
            status_absensi = 'siap_pulang'
            btn_label = 'KIRIM ABSEN PULANG'
        else:
            status_absensi = 'selesai'

        if now < event.start_time:
            event_not_started = True
        elif status_absensi != 'selesai' and now > event.end_time:
            event_expired = True

    raw_history = Attendance.objects.filter(user=request.user).select_related('event').order_by('-event__start_time', 'created_at')
    history_map = {}
    for row in raw_history:
        eid = row.event.id
        if eid not in history_map:
            history_map[eid] = {'title': row.event.title, 'in': '-', 'out': '-', 'status': 'Hadir'}
        waktu = timezone.localtime(row.created_at).strftime('%H:%M')
        if row.type == 'in':
            history_map[eid]['in'] = waktu
        elif row.type == 'out':
            history_map[eid]['out'] = waktu
        elif row.type == 'izin':
            history_map[eid]['in'] = 'IZIN'
            history_map[eid]['out'] = 'IZIN'
            history_map[eid]['status'] = f"Izin: {row.notes}"

    paginator = Paginator(list(history_map.values()), 10)
    history_page = paginator.get_page(request.GET.get('hal', 1))

    return render(request, 'absensi/member.html', {
        'event': event,
        'status_absensi': status_absensi,
        'btn_label': btn_label,
        'event_not_started': event_not_started,
        'event_expired': event_expired,
        'data_in': data_in,
        'data_izin': data_izin,
        'history_page': history_page,
    })