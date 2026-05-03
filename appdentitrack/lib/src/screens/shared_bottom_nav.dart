import 'dart:async';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'dashboard_screen.dart';
import 'offense_screen.dart';
import 'service_history_screen.dart';
import 'alert_screen.dart';
import 'profile_screen.dart';

class SharedBottomNav extends StatefulWidget {
  final int currentIndex;
  final String studentId;
  final String studentName;

  const SharedBottomNav({
    super.key,
    required this.currentIndex,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<SharedBottomNav> createState() => _SharedBottomNavState();
}

class _SharedBottomNavState extends State<SharedBottomNav> {
  bool _activeSession = false;
  bool _recentLogout = false;
  String _activeSessionId = '';
  String _recentLogoutId = '';
  String _seenActiveSessionId = '';
  String _seenRecentLogoutId = '';
  int _unseenOffensesCount = 0;
  int _totalAlertsCount = 0;
  int _seenAlertsCount = 0;
  Timer? _prefsTimer;

  @override
  void initState() {
    super.initState();
    _loadPrefs();
    _prefsTimer = Timer.periodic(const Duration(seconds: 2), (_) {
      if (mounted) {
        _loadPrefs();
      }
    });
  }

  @override
  void dispose() {
    _prefsTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadPrefs() async {
    final prefs = await SharedPreferences.getInstance();
    if (mounted) {
      setState(() {
        _activeSession = prefs.getBool('active_service_session') ?? false;
        _recentLogout = prefs.getBool('recent_service_logout') ?? false;
        _activeSessionId = prefs.getString('active_service_session_id') ?? '';
        _recentLogoutId = prefs.getString('recent_service_logout_id') ?? '';
        _seenActiveSessionId = prefs.getString('seen_active_session_id') ?? '';
        _seenRecentLogoutId = prefs.getString('seen_recent_logout_id') ?? '';
        _unseenOffensesCount = prefs.getInt('unseen_offenses_count') ?? 0;
        _totalAlertsCount = prefs.getInt('total_alerts_count') ?? 0;
        _seenAlertsCount = prefs.getInt('seen_alerts_count') ?? 0;
      });
    }
  }

  void _onTap(int i) async {
    if (i == widget.currentIndex) return;

    if (i == 2) {
      final prefs = await SharedPreferences.getInstance();
      if (_activeSessionId.isNotEmpty) {
        await prefs.setString('seen_active_session_id', _activeSessionId);
      }
      if (_recentLogoutId.isNotEmpty) {
        await prefs.setString('seen_recent_logout_id', _recentLogoutId);
      }
    } else if (i == 1) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt('unseen_offenses_count', 0);
      setState(() {
        _unseenOffensesCount = 0;
      });
    } else if (i == 3) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt('seen_alerts_count', _totalAlertsCount);
      setState(() {
        _seenAlertsCount = _totalAlertsCount;
      });
    }

    Widget screen;
    switch (i) {
      case 0:
        screen = DashboardScreen(studentId: widget.studentId, studentName: widget.studentName);
        break;
      case 1:
        screen = OffenseScreen(studentId: widget.studentId, studentName: widget.studentName);
        break;
      case 2:
        screen = ServiceHistoryScreen(studentId: widget.studentId, studentName: widget.studentName);
        break;
      case 3:
        screen = AlertsScreen(studentId: widget.studentId, studentName: widget.studentName);
        break;
      case 4:
        screen = ProfileScreen(studentId: widget.studentId, studentName: widget.studentName);
        break;
      default:
        return;
    }

    if (i == 0) {
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => screen));
    } else {
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    }
  }

  @override
  Widget build(BuildContext context) {
    final bool showGreenBadge = _activeSession && (_activeSessionId != _seenActiveSessionId);
    final bool showRedBadge = _recentLogout && (_recentLogoutId != _seenRecentLogoutId) && !showGreenBadge;
    final bool showBadge = showGreenBadge || showRedBadge;
    final Color badgeColor = showGreenBadge ? Colors.green : Colors.red;

    return BottomNavigationBar(
      currentIndex: widget.currentIndex,
      onTap: _onTap,
      type: BottomNavigationBarType.fixed,
      selectedItemColor: const Color(0xFF193B8C),
      unselectedItemColor: Colors.grey.shade500,
      selectedLabelStyle: const TextStyle(fontWeight: FontWeight.w800),
      items: [
        const BottomNavigationBarItem(
          icon: Icon(Icons.home_outlined),
          activeIcon: Icon(Icons.home_rounded),
          label: 'Home',
        ),
        BottomNavigationBarItem(
          icon: Badge(
            isLabelVisible: _unseenOffensesCount > 0,
            backgroundColor: Colors.red,
            smallSize: 10,
            child: const Icon(Icons.description_outlined),
          ),
          activeIcon: Badge(
            isLabelVisible: _unseenOffensesCount > 0,
            backgroundColor: Colors.red,
            smallSize: 10,
            child: const Icon(Icons.description_rounded),
          ),
          label: 'Offenses',
        ),
        BottomNavigationBarItem(
          icon: Badge(
            isLabelVisible: showBadge,
            backgroundColor: badgeColor,
            smallSize: 10,
            child: const Icon(Icons.access_time_outlined),
          ),
          activeIcon: Badge(
            isLabelVisible: showBadge,
            backgroundColor: badgeColor,
            smallSize: 10,
            child: const Icon(Icons.access_time_rounded),
          ),
          label: 'Service',
        ),
        BottomNavigationBarItem(
          icon: Badge(
            isLabelVisible: _totalAlertsCount > _seenAlertsCount,
            backgroundColor: Colors.red,
            smallSize: 10,
            child: const Icon(Icons.notifications_none_rounded),
          ),
          activeIcon: Badge(
            isLabelVisible: _totalAlertsCount > _seenAlertsCount,
            backgroundColor: Colors.red,
            smallSize: 10,
            child: const Icon(Icons.notifications_rounded),
          ),
          label: 'Alerts',
        ),
        const BottomNavigationBarItem(
          icon: Icon(Icons.person_outline_rounded),
          activeIcon: Icon(Icons.person_rounded),
          label: 'Profile',
        ),
      ],
    );
  }
}
