from django.urls import path
from . import views

urlpatterns = [
    path('login/', views.login_view, name='login'),
    path('logout/', views.logout_view, name='logout'),
    path('', views.admin_dashboard, name='admin_dashboard'),
    path('logs/', views.attendance_logs, name='attendance_logs'),
    path('logs/export/', views.export_attendance_excel, name='export_attendance_excel'),
    path('event/create/', views.event_create, name='event_create'),
    path('event/edit/<int:event_id>/', views.event_edit, name='event_edit'),
    path('event/delete/<int:event_id>/', views.event_delete, name='event_delete'),
    path('members/', views.members_list, name='members_list'),
    path('members/template/', views.download_members_template, name='download_members_template'),
    path('members/import/', views.import_members_excel, name='import_members_excel'),
    path('members/delete/<int:user_id>/', views.member_delete, name='member_delete'),
    path('member/', views.member_dashboard, name='member_dashboard'),
]