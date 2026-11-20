from django.db import models
from django.contrib.auth.models import AbstractUser

class User(AbstractUser):
    ROLE_CHOICES = (
        ('member', 'Anggota'),
        ('pengurus', 'Pengurus'),
        ('admin', 'Admin'),
    )
    RT_CHOICES = (
        ('05', 'RT 05'),
        ('06', 'RT 06'),
        ('07', 'RT 07'),
        ('08', 'RT 08'),
        ('09', 'RT 09'),
    )
    name = models.CharField(max_length=100)
    role = models.CharField(max_length=15, choices=ROLE_CHOICES, default='member')
    rt = models.CharField(max_length=5, choices=RT_CHOICES, blank=True, null=True)

    def __str__(self):
        return f"{self.name} ({self.role})"

class Event(models.Model):
    title = models.CharField(max_length=150)
    start_time = models.DateTimeField()
    end_time = models.DateTimeField()
    lat = models.DecimalField(max_digits=10, decimal_places=8)
    lon = models.DecimalField(max_digits=11, decimal_places=8)
    tolerance_meters = models.IntegerField(default=50)
    is_active = models.BooleanField(default=True)

    def __str__(self):
        return self.title

class Attendance(models.Model):
    TYPE_CHOICES = (
        ('in', 'Datang'),
        ('out', 'Pulang'),
        ('izin', 'Izin'),
    )
    STATUS_CHOICES = (
        ('valid', 'Valid'),
        ('invalid', 'Invalid'),
        ('pending', 'Pending'),
    )
    user = models.ForeignKey(User, on_delete=models.CASCADE)
    event = models.ForeignKey(Event, on_delete=models.CASCADE)
    type = models.CharField(max_length=10, choices=TYPE_CHOICES, default='in')
    selfie = models.ImageField(upload_to='selfies/', blank=True, null=True)
    latitude = models.DecimalField(max_digits=10, decimal_places=8)
    longitude = models.DecimalField(max_digits=11, decimal_places=8)
    status = models.CharField(max_length=10, choices=STATUS_CHOICES, default='pending')
    notes = models.TextField(blank=True, null=True)
    created_at = models.DateTimeField(auto_now_add=True)