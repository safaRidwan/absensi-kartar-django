from django.contrib import admin
from .models import User, Event, Attendance

admin.site.register(User)
admin.site.register(Event)
admin.site.register(Attendance)