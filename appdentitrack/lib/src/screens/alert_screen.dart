import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'services/alerts_api.dart';
import 'shared_bottom_nav.dart';

const Color blue = Color(0xFF1A73E8); // adjust to your actual color
const Color blueDark = Color(0xFF0D47A1); // adjust to your actual color

class AlertsScreen extends StatefulWidget {
  final String studentId;
  final String studentName;

  const AlertsScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<AlertsScreen> createState() => _AlertsScreenState();
}

class _AlertsScreenState extends State<AlertsScreen> {
  final _api = AlertsApi();
  List<StudentAlert> _alerts = [];
  Set<String> _dismissedAlerts = {};
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final prefs = await SharedPreferences.getInstance();
      final dismissed = prefs.getStringList('dismissed_alerts') ?? [];
      _dismissedAlerts = dismissed.toSet();

      final alerts = await _api.getAlerts(widget.studentId);
      setState(() {
        _alerts = alerts
            .where(
              (a) =>
                  !_dismissedAlerts.contains('${a.alertType}_${a.createdAt}'),
            )
            .toList();
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = 'Failed to load alerts. Please try again.';
        _loading = false;
      });
    }
  }

  String _formatDate(dynamic date) {
    // Adjust to your actual date formatting logic
    return date.toString();
  }

  Widget _alertCard(StudentAlert alert) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border(
          left: BorderSide(color: alert.badgeColor, width: 4),
          top: BorderSide(color: Colors.grey.shade200),
          right: BorderSide(color: Colors.grey.shade200),
          bottom: BorderSide(color: Colors.grey.shade200),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: alert.badgeColor.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(
                    alert.badgeIcon,
                    color: alert.badgeColor,
                    size: 20,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        alert.title,
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 14,
                          color: blueDark,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      Text(
                        _formatDate(alert.createdAt),
                        style: TextStyle(
                          fontSize: 11,
                          color: Colors.grey.shade500,
                        ),
                      ),
                    ],
                  ),
                ),
                InkWell(
                  onTap: () async {
                    final id = '${alert.alertType}_${alert.createdAt}';
                    final prefs = await SharedPreferences.getInstance();
                    final current =
                        prefs.getStringList('dismissed_alerts') ?? [];
                    if (!current.contains(id)) {
                      current.add(id);
                      await prefs.setStringList('dismissed_alerts', current);
                    }
                    setState(() {
                      _dismissedAlerts.add(id);
                      _alerts.remove(alert);
                    });
                  },
                  borderRadius: BorderRadius.circular(20),
                  child: Padding(
                    padding: const EdgeInsets.all(8.0),
                    child: Icon(
                      Icons.close_rounded,
                      size: 20,
                      color: Colors.grey.shade400,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              alert.message,
              style: TextStyle(
                fontSize: 13,
                height: 1.4,
                color: Colors.grey.shade800,
              ),
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: blue,
      appBar: AppBar(
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text(
          'Alerts',
          style: TextStyle(fontWeight: FontWeight.w900),
        ),
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _load,
          color: blue,
          backgroundColor: Colors.white,
          child: Container(
            width: double.infinity,
            decoration: const BoxDecoration(
              color: Color(0xFFF5F6FB),
              borderRadius: BorderRadius.only(
                topLeft: Radius.circular(28),
                topRight: Radius.circular(28),
              ),
            ),
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(_error!, textAlign: TextAlign.center),
                          const SizedBox(height: 10),
                          ElevatedButton(
                            onPressed: _load,
                            child: const Text('Retry'),
                          ),
                        ],
                      ),
                    ),
                  )
                : _alerts.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(28),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(
                            Icons.notifications_none_rounded,
                            size: 64,
                            color: Colors.grey.shade300,
                          ),
                          const SizedBox(height: 16),
                          const Text(
                            'No alerts yet',
                            style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w700,
                              color: blueDark,
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'You\'re all caught up!',
                            style: TextStyle(color: Colors.grey.shade600),
                          ),
                        ],
                      ),
                    ),
                  )
                : ListView(
                    padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
                    children: [
                      Text(
                        'Notifications',
                        style: TextStyle(
                          color: Colors.grey.shade700,
                          fontWeight: FontWeight.w900,
                          fontSize: 16,
                        ),
                      ),
                      const SizedBox(height: 12),
                      ..._alerts.map((alert) => _alertCard(alert)),
                    ],
                  ),
          ),
        ),
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 3,
        studentId: widget.studentId,
        studentName: widget.studentName,
      ),
    );
  }
}
