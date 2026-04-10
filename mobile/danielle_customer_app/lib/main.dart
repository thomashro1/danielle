import 'dart:convert';
import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

const String kApiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'https://himi10.de/danielle/customer_api.php',
);

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const DanielleApp());
}

class DanielleApp extends StatelessWidget {
  const DanielleApp({super.key});

  @override
  Widget build(BuildContext context) {
    const seed = Color(0xFFB44F2C);
    final scheme = ColorScheme.fromSeed(
      seedColor: seed,
      brightness: Brightness.light,
      primary: seed,
      secondary: const Color(0xFF245B50),
      surface: const Color(0xFFFFFBF6),
    );

    return MaterialApp(
      title: 'DANIELLE',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: scheme,
        scaffoldBackgroundColor: const Color(0xFFF5EFE7),
        cardTheme: CardThemeData(
          color: const Color(0xFFFFFCF8),
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(28),
            side: const BorderSide(color: Color(0xFFDCCFBE)),
          ),
        ),
        snackBarTheme: SnackBarThemeData(
          behavior: SnackBarBehavior.floating,
          backgroundColor: const Color(0xFF2E261F),
          contentTextStyle: const TextStyle(color: Colors.white),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: Colors.white,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(18),
            borderSide: const BorderSide(color: Color(0xFFDCCFBE)),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(18),
            borderSide: const BorderSide(color: Color(0xFFDCCFBE)),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(18),
            borderSide: const BorderSide(color: Color(0xFFB44F2C), width: 1.4),
          ),
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 18,
            vertical: 16,
          ),
        ),
      ),
      home: SessionGate(
        api: CustomerApiClient(baseUrl: kApiBaseUrl),
        store: const TokenStore(),
      ),
    );
  }
}

class SessionGate extends StatefulWidget {
  const SessionGate({super.key, required this.api, required this.store});

  final CustomerApiClient api;
  final TokenStore store;

  @override
  State<SessionGate> createState() => _SessionGateState();
}

class _SessionGateState extends State<SessionGate> {
  bool _isBootstrapping = true;
  String? _token;
  Customer? _customer;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    final token = await widget.store.readToken();
    if (token == null || token.isEmpty) {
      if (!mounted) return;
      setState(() {
        _isBootstrapping = false;
      });
      return;
    }

    try {
      final customer = await widget.api.getMe(token);
      if (!mounted) return;
      setState(() {
        _token = token;
        _customer = customer;
        _isBootstrapping = false;
      });
    } catch (_) {
      await widget.store.clearToken();
      if (!mounted) return;
      setState(() {
        _token = null;
        _customer = null;
        _isBootstrapping = false;
      });
    }
  }

  Future<void> _handleLogin(LoginResult result) async {
    await widget.store.writeToken(result.token);
    if (!mounted) return;
    setState(() {
      _token = result.token;
      _customer = result.customer;
    });
  }

  Future<void> _handleLogout() async {
    final token = _token;
    setState(() {
      _token = null;
      _customer = null;
      _isBootstrapping = false;
    });
    await widget.store.clearToken();
    if (token == null || token.isEmpty) {
      return;
    }

    try {
      await widget.api.logout(token);
    } catch (_) {
      // Logout failures are not critical locally.
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isBootstrapping) {
      return const SplashScreen();
    }

    if (_token == null || _customer == null) {
      return LoginScreen(api: widget.api, onLogin: _handleLogin);
    }

    return HomeShell(
      api: widget.api,
      token: _token!,
      customer: _customer!,
      onLogout: _handleLogout,
      onCustomerChanged: (customer) {
        setState(() {
          _customer = customer;
        });
      },
      onSessionExpired: _handleLogout,
    );
  }
}

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFFF9F2E8), Color(0xFFF0E5D6)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: const Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _AppBadge(),
              SizedBox(height: 18),
              CircularProgressIndicator(),
              SizedBox(height: 18),
              Text('Kundenportal wird vorbereitet...'),
            ],
          ),
        ),
      ),
    );
  }
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.api, required this.onLogin});

  final CustomerApiClient api;
  final Future<void> Function(LoginResult result) onLogin;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isSubmitting = false;
  String? _errorText;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _isSubmitting = true;
      _errorText = null;
    });

    try {
      final result = await widget.api.login(
        email: _emailController.text.trim(),
        password: _passwordController.text,
        deviceName: 'Android App',
      );
      await widget.onLogin(result);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _errorText = error.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _errorText = 'Die Anmeldung konnte nicht durchgefuehrt werden.';
      });
    } finally {
      if (mounted) {
        setState(() {
          _isSubmitting = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFFF9F2E8), Color(0xFFE8DDD0)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const _AppBadge(),
                        const SizedBox(height: 18),
                        Text(
                          'Willkommen zurueck',
                          style: Theme.of(context).textTheme.headlineMedium
                              ?.copyWith(
                                color: const Color(0xFF2E261F),
                                fontWeight: FontWeight.w700,
                              ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Melden Sie sich mit Ihrer Kunden-E-Mail an, um Status, Dokumente und Anliegen mobil zu verwalten.',
                          style: Theme.of(context).textTheme.bodyLarge
                              ?.copyWith(color: const Color(0xFF6E6258)),
                        ),
                        const SizedBox(height: 24),
                        TextField(
                          controller: _emailController,
                          keyboardType: TextInputType.emailAddress,
                          decoration: const InputDecoration(
                            labelText: 'E-Mail',
                            hintText: 'kunde@example.com',
                          ),
                        ),
                        const SizedBox(height: 14),
                        TextField(
                          controller: _passwordController,
                          obscureText: true,
                          decoration: const InputDecoration(
                            labelText: 'Passwort',
                          ),
                          onSubmitted: (_) {
                            if (!_isSubmitting) {
                              _submit();
                            }
                          },
                        ),
                        if (_errorText != null) ...[
                          const SizedBox(height: 14),
                          Text(
                            _errorText!,
                            style: const TextStyle(color: Color(0xFF9D2B1F)),
                          ),
                        ],
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: _isSubmitting ? null : _submit,
                          style: FilledButton.styleFrom(
                            minimumSize: const Size.fromHeight(54),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(18),
                            ),
                          ),
                          child: _isSubmitting
                              ? const SizedBox(
                                  width: 22,
                                  height: 22,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2.2,
                                  ),
                                )
                              : const Text('Anmelden'),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class HomeShell extends StatefulWidget {
  const HomeShell({
    super.key,
    required this.api,
    required this.token,
    required this.customer,
    required this.onLogout,
    required this.onCustomerChanged,
    required this.onSessionExpired,
  });

  final CustomerApiClient api;
  final String token;
  final Customer customer;
  final VoidCallback onLogout;
  final ValueChanged<Customer> onCustomerChanged;
  final Future<void> Function() onSessionExpired;

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  List<String> get _titles => const [
    'Uebersicht',
    'Status',
    'Dokumente',
    'Konto',
  ];

  Future<void> _confirmLogout() async {
    final shouldLogout = await showDialog<bool>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('Wirklich abmelden?'),
          content: const Text('Ihre Sitzung auf diesem Geraet wird beendet.'),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('Abbrechen'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('Abmelden'),
            ),
          ],
        );
      },
    );

    if (shouldLogout == true) {
      widget.onLogout();
    }
  }

  @override
  Widget build(BuildContext context) {
    final tabs = [
      DashboardScreen(
        api: widget.api,
        token: widget.token,
        customer: widget.customer,
        onSessionExpired: widget.onSessionExpired,
      ),
      StatusScreen(
        api: widget.api,
        token: widget.token,
        onSessionExpired: widget.onSessionExpired,
      ),
      DocumentsScreen(
        api: widget.api,
        token: widget.token,
        onSessionExpired: widget.onSessionExpired,
      ),
      AccountScreen(
        api: widget.api,
        token: widget.token,
        customer: widget.customer,
        onCustomerChanged: widget.onCustomerChanged,
        onSessionExpired: widget.onSessionExpired,
      ),
    ];

    return Scaffold(
      appBar: AppBar(
        title: Text(_titles[_index]),
        actions: [
          IconButton(
            tooltip: 'Abmelden',
            onPressed: _confirmLogout,
            icon: const Icon(Icons.logout_rounded),
          ),
        ],
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFFF7F1EA), Color(0xFFF2E8DB)],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: SafeArea(
          top: false,
          child: IndexedStack(index: _index, children: tabs),
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (index) {
          setState(() {
            _index = index;
          });
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            selectedIcon: Icon(Icons.dashboard_rounded),
            label: 'Home',
          ),
          NavigationDestination(
            icon: Icon(Icons.timeline_outlined),
            selectedIcon: Icon(Icons.timeline_rounded),
            label: 'Status',
          ),
          NavigationDestination(
            icon: Icon(Icons.folder_open_outlined),
            selectedIcon: Icon(Icons.folder_rounded),
            label: 'Dokumente',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline_rounded),
            selectedIcon: Icon(Icons.person_rounded),
            label: 'Konto',
          ),
        ],
      ),
    );
  }
}

class _AppBadge extends StatelessWidget {
  const _AppBadge();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: const Color(0xFFF3E2D7),
        borderRadius: BorderRadius.circular(999),
      ),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.favorite_outline_rounded,
            color: Color(0xFFB44F2C),
            size: 18,
          ),
          SizedBox(width: 8),
          Text(
            'DANIELLE Kundenportal',
            style: TextStyle(
              color: Color(0xFF2E261F),
              fontWeight: FontWeight.w700,
              letterSpacing: 0.2,
            ),
          ),
        ],
      ),
    );
  }
}

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({
    super.key,
    required this.api,
    required this.token,
    required this.customer,
    required this.onSessionExpired,
  });

  final CustomerApiClient api;
  final String token;
  final Customer customer;
  final Future<void> Function() onSessionExpired;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  List<NetworkPartner> _partners = const [];
  bool _isLoading = true;
  bool _isSendingProblem = false;
  final _problemController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _problemController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _isLoading = true;
    });
    try {
      final partners = await widget.api.getNetworkPartners(widget.token);
      if (!mounted) return;
      setState(() {
        _partners = partners;
      });
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      if (mounted) {
        _showSnack(error.message);
      }
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _sendProblem() async {
    final note = _problemController.text.trim();
    if (note.isEmpty) {
      _showSnack('Bitte beschreiben Sie Ihr Anliegen.');
      return;
    }

    setState(() {
      _isSendingProblem = true;
    });

    try {
      await widget.api.reportProblem(widget.token, note);
      if (!mounted) return;
      _problemController.clear();
      _showSnack('Ihr Anliegen wurde an das Backoffice uebermittelt.');
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    } finally {
      if (mounted) {
        setState(() {
          _isSendingProblem = false;
        });
      }
    }
  }

  void _showSnack(String message) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _callPartner(NetworkPartner partner) async {
    if (partner.telefon.isEmpty) {
      _showSnack('Fuer diesen Kontakt ist keine Telefonnummer hinterlegt.');
      return;
    }

    final uri = Uri(scheme: 'tel', path: partner.telefon);
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      _showSnack('Der Anruf konnte nicht gestartet werden.');
    }
  }

  Future<void> _mailPartner(NetworkPartner partner) async {
    if (partner.email.isEmpty) {
      _showSnack('Fuer diesen Kontakt ist keine E-Mail-Adresse hinterlegt.');
      return;
    }

    final uri = Uri(
      scheme: 'mailto',
      path: partner.email,
      queryParameters: <String, String>{'subject': 'DANIELLE Kundenanfrage'},
    );
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      _showSnack('Die Mail-App konnte nicht geoeffnet werden.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final customer = widget.customer;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 120),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Hallo ${customer.displayName}',
                    style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: const Color(0xFF2E261F),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Ihr mobiles Kundenportal buendelt Statusmeldungen, Dokumente und direkte Anliegen an das Backoffice.',
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                      color: const Color(0xFF6E6258),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Problem melden',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Beschreiben Sie Ihr Anliegen kurz. Das Backoffice erhaelt automatisch eine neue Wiedervorlage.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: const Color(0xFF6E6258),
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _problemController,
                    minLines: 4,
                    maxLines: 6,
                    decoration: const InputDecoration(
                      labelText: 'Beschreibung',
                      alignLabelWithHint: true,
                    ),
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: _isSendingProblem ? null : _sendProblem,
                    icon: _isSendingProblem
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.send_rounded),
                    label: const Text('Anliegen absenden'),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'Mein Netzwerk',
            style: Theme.of(
              context,
            ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          if (_isLoading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_partners.isEmpty)
            const Card(
              child: Padding(
                padding: EdgeInsets.all(20),
                child: Text('Aktuell sind keine Netzwerkpartner hinterlegt.'),
              ),
            )
          else
            ..._partners.map(
              (partner) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          partner.displayName,
                          style: Theme.of(context).textTheme.titleMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                        const SizedBox(height: 6),
                        if (partner.typeLabel.isNotEmpty)
                          Text(
                            partner.typeLabel,
                            style: Theme.of(context).textTheme.bodySmall
                                ?.copyWith(color: const Color(0xFF245B50)),
                          ),
                        if (partner.personName.isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text(partner.personName),
                        ],
                        if (partner.adresse.isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text(
                            partner.adresse,
                            style: Theme.of(context).textTheme.bodySmall
                                ?.copyWith(color: const Color(0xFF6E6258)),
                          ),
                        ],
                        const SizedBox(height: 14),
                        Wrap(
                          spacing: 10,
                          runSpacing: 10,
                          children: [
                            OutlinedButton.icon(
                              onPressed: partner.telefon.isEmpty
                                  ? null
                                  : () => _callPartner(partner),
                              icon: const Icon(Icons.call_rounded),
                              label: const Text('Anrufen'),
                            ),
                            OutlinedButton.icon(
                              onPressed: partner.email.isEmpty
                                  ? null
                                  : () => _mailPartner(partner),
                              icon: const Icon(Icons.mail_outline_rounded),
                              label: const Text('Mail senden'),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class StatusScreen extends StatefulWidget {
  const StatusScreen({
    super.key,
    required this.api,
    required this.token,
    required this.onSessionExpired,
  });

  final CustomerApiClient api;
  final String token;
  final Future<void> Function() onSessionExpired;

  @override
  State<StatusScreen> createState() => _StatusScreenState();
}

class _StatusScreenState extends State<StatusScreen> {
  List<StatusType> _types = const [];
  List<StatusEntry> _items = const [];
  bool _isLoading = true;
  bool _isSaving = false;
  int? _selectedTypeId;
  final _noteController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _noteController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _isLoading = true;
    });
    try {
      final results = await Future.wait([
        widget.api.getStatusTypes(widget.token),
        widget.api.getStatusEntries(widget.token),
      ]);
      if (!mounted) return;
      final types = results[0] as List<StatusType>;
      final items = results[1] as List<StatusEntry>;
      final previousSelection = _selectedTypeId;
      final selectionExists = types.any((type) => type.id == previousSelection);

      setState(() {
        _types = types;
        _items = items;
        _selectedTypeId = selectionExists
            ? previousSelection
            : (types.isNotEmpty ? types.first.id : null);
      });
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _saveStatus() async {
    if (_selectedTypeId == null) {
      _showSnack('Bitte zuerst einen Status auswaehlen.');
      return;
    }

    setState(() {
      _isSaving = true;
    });
    try {
      await widget.api.addStatus(
        widget.token,
        statusId: _selectedTypeId!,
        note: _noteController.text.trim(),
      );
      if (!mounted) return;
      _noteController.clear();
      _showSnack('Status wurde gespeichert.');
      await _load();
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    } finally {
      if (mounted) {
        setState(() {
          _isSaving = false;
        });
      }
    }
  }

  void _showSnack(String message) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 120),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Status setzen',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Teilen Sie Ihren aktuellen Stand mit und ergaenzen Sie bei Bedarf eine Notiz.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: const Color(0xFF6E6258),
                    ),
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<int>(
                    initialValue: _selectedTypeId,
                    items: _types
                        .map(
                          (type) => DropdownMenuItem<int>(
                            value: type.id,
                            child: Text(type.label),
                          ),
                        )
                        .toList(),
                    onChanged: (value) {
                      setState(() {
                        _selectedTypeId = value;
                      });
                    },
                    decoration: const InputDecoration(labelText: 'Status'),
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: _noteController,
                    minLines: 3,
                    maxLines: 5,
                    decoration: const InputDecoration(
                      labelText: 'Notiz',
                      alignLabelWithHint: true,
                    ),
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: _isSaving ? null : _saveStatus,
                    icon: _isSaving
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.add_task_rounded),
                    label: const Text('Status speichern'),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'Historie',
            style: Theme.of(
              context,
            ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          if (_isLoading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_items.isEmpty)
            const Card(
              child: Padding(
                padding: EdgeInsets.all(20),
                child: Text('Noch keine Statuseintraege vorhanden.'),
              ),
            )
          else
            ..._items.map(
              (item) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          item.label,
                          style: Theme.of(context).textTheme.titleMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          '${formatDate(item.createdAt)} | ${item.user}',
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(color: const Color(0xFF6E6258)),
                        ),
                        if (item.note.isNotEmpty) ...[
                          const SizedBox(height: 10),
                          Text(item.note),
                        ],
                      ],
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class DocumentsScreen extends StatefulWidget {
  const DocumentsScreen({
    super.key,
    required this.api,
    required this.token,
    required this.onSessionExpired,
  });

  final CustomerApiClient api;
  final String token;
  final Future<void> Function() onSessionExpired;

  @override
  State<DocumentsScreen> createState() => _DocumentsScreenState();
}

class _DocumentsScreenState extends State<DocumentsScreen> {
  List<DocumentEntry> _documents = const [];
  bool _isLoading = true;
  bool _isUploading = false;
  int? _downloadingId;
  final _imagePicker = ImagePicker();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _isLoading = true;
    });

    try {
      final documents = await widget.api.getDocuments(widget.token);
      if (!mounted) return;
      setState(() {
        _documents = documents;
      });
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _pickDocument() async {
    final result = await FilePicker.platform.pickFiles(
      allowMultiple: false,
      withData: false,
    );
    if (result == null || result.files.isEmpty) {
      return;
    }

    final selected = result.files.single;
    final path = selected.path;
    if (path == null || path.isEmpty) {
      _showSnack('Die Datei konnte lokal nicht gelesen werden.');
      return;
    }

    await _startUploadFlow(
      File(path),
      suggestedLabel: _defaultDocumentLabel(selected.name),
      sourceLabel: 'Dokument',
    );
  }

  Future<void> _capturePhoto() async {
    final photo = await _imagePicker.pickImage(
      source: ImageSource.camera,
      imageQuality: 88,
    );
    if (photo == null) {
      return;
    }

    final fallbackName = p.basename(photo.path);
    await _startUploadFlow(
      File(photo.path),
      suggestedLabel: _defaultDocumentLabel(
        photo.name.isNotEmpty ? photo.name : fallbackName,
      ),
      sourceLabel: 'Kamera',
    );
  }

  Future<void> _startUploadFlow(
    File file, {
    required String suggestedLabel,
    required String sourceLabel,
  }) async {
    final draft = await _showUploadPreview(
      file: file,
      suggestedLabel: suggestedLabel,
      sourceLabel: sourceLabel,
    );
    if (draft == null) {
      return;
    }

    setState(() {
      _isUploading = true;
    });

    try {
      await widget.api.uploadDocument(
        widget.token,
        file,
        label: draft.label,
        note: draft.note,
      );
      if (!mounted) return;
      _showSnack('Dokument wurde hochgeladen.');
      await _load();
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    } finally {
      if (mounted) {
        setState(() {
          _isUploading = false;
        });
      }
    }
  }

  Future<UploadDraft?> _showUploadPreview({
    required File file,
    required String suggestedLabel,
    required String sourceLabel,
  }) async {
    final labelController = TextEditingController(text: suggestedLabel);
    final noteController = TextEditingController();
    final isImage = _looksLikeImage(file.path);
    final filename = p.basename(file.path);

    try {
      return await showDialog<UploadDraft>(
        context: context,
        builder: (context) {
          return AlertDialog(
            title: const Text('Upload vorbereiten'),
            content: SizedBox(
              width: double.maxFinite,
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      sourceLabel,
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                        color: const Color(0xFF6E6258),
                      ),
                    ),
                    const SizedBox(height: 12),
                    if (isImage)
                      ClipRRect(
                        borderRadius: BorderRadius.circular(20),
                        child: Image.file(
                          file,
                          height: 220,
                          width: double.infinity,
                          fit: BoxFit.cover,
                        ),
                      )
                    else
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(18),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF6EEE5),
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(color: const Color(0xFFDCCFBE)),
                        ),
                        child: Column(
                          children: [
                            Icon(
                              _iconForMime(_mimeFromFilename(filename)),
                              size: 44,
                              color: const Color(0xFFB44F2C),
                            ),
                            const SizedBox(height: 12),
                            Text(
                              filename,
                              textAlign: TextAlign.center,
                              style: Theme.of(context).textTheme.bodyMedium,
                            ),
                          ],
                        ),
                      ),
                    const SizedBox(height: 16),
                    TextField(
                      controller: labelController,
                      decoration: const InputDecoration(
                        labelText: 'Bezeichnung',
                        hintText: 'z. B. Arztbericht',
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: noteController,
                      minLines: 3,
                      maxLines: 5,
                      decoration: const InputDecoration(
                        labelText: 'Bemerkung',
                        alignLabelWithHint: true,
                        hintText: 'Optionaler Hinweis zum Upload',
                      ),
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Verwerfen'),
              ),
              FilledButton(
                onPressed: () {
                  Navigator.of(context).pop(
                    UploadDraft(
                      label: labelController.text.trim(),
                      note: noteController.text.trim(),
                    ),
                  );
                },
                child: const Text('Hochladen'),
              ),
            ],
          );
        },
      );
    } finally {
      labelController.dispose();
      noteController.dispose();
    }
  }

  Future<void> _downloadDocument(DocumentEntry document) async {
    setState(() {
      _downloadingId = document.id;
    });

    try {
      final file = await widget.api.downloadDocument(widget.token, document);
      await OpenFilex.open(file.path);
      if (!mounted) return;
      _showSnack('Dokument wurde heruntergeladen.');
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    } catch (_) {
      _showSnack('Das Dokument konnte nicht geoeffnet werden.');
    } finally {
      if (mounted) {
        setState(() {
          _downloadingId = null;
        });
      }
    }
  }

  void _showSnack(String message) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 120),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Dokumente mobil verwalten',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Waehlen Sie eine Datei oder erstellen Sie direkt ein Foto. Vor dem Upload sehen Sie immer zuerst eine Vorschau.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: const Color(0xFF6E6258),
                    ),
                  ),
                  const SizedBox(height: 16),
                  LayoutBuilder(
                    builder: (context, constraints) {
                      final buttons = [
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: _isUploading ? null : _pickDocument,
                            icon: _isUploading
                                ? const SizedBox(
                                    width: 16,
                                    height: 16,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                    ),
                                  )
                                : const Icon(Icons.upload_file_rounded),
                            label: const Text('Dokument waehlen'),
                          ),
                        ),
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: _isUploading ? null : _capturePhoto,
                            icon: const Icon(Icons.photo_camera_outlined),
                            label: const Text('Kamera'),
                          ),
                        ),
                      ];

                      if (constraints.maxWidth >= 420) {
                        return Row(
                          children: [
                            buttons[0],
                            const SizedBox(width: 12),
                            buttons[1],
                          ],
                        );
                      }

                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          buttons[0],
                          const SizedBox(height: 12),
                          buttons[1],
                        ],
                      );
                    },
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'Verfuegbare Unterlagen',
            style: Theme.of(
              context,
            ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          if (_isLoading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_documents.isEmpty)
            const Card(
              child: Padding(
                padding: EdgeInsets.all(20),
                child: Text('Aktuell sind keine Dokumente hinterlegt.'),
              ),
            )
          else
            ..._documents.map(
              (document) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Card(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(28),
                    onTap: _downloadingId == document.id
                        ? null
                        : () => _downloadDocument(document),
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          CircleAvatar(
                            radius: 22,
                            backgroundColor: const Color(0xFFF3E2D7),
                            child: Icon(
                              _iconForMime(document.mimeType),
                              color: const Color(0xFFB44F2C),
                            ),
                          ),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  document.displayLabel,
                                  style: Theme.of(context).textTheme.titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w700),
                                ),
                                const SizedBox(height: 6),
                                Text(
                                  document.filename,
                                  style: Theme.of(context).textTheme.bodyMedium
                                      ?.copyWith(
                                        color: const Color(0xFF6E6258),
                                      ),
                                ),
                                if (document.note.isNotEmpty) ...[
                                  const SizedBox(height: 6),
                                  Text(document.note),
                                ],
                                const SizedBox(height: 6),
                                Text(
                                  '${formatDate(document.createdAt)} | ${document.createdBy}',
                                  style: Theme.of(context).textTheme.bodySmall
                                      ?.copyWith(
                                        color: const Color(0xFF6E6258),
                                      ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 12),
                          IconButton.filledTonal(
                            onPressed: _downloadingId == document.id
                                ? null
                                : () => _downloadDocument(document),
                            icon: _downloadingId == document.id
                                ? const SizedBox(
                                    width: 18,
                                    height: 18,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                    ),
                                  )
                                : const Icon(Icons.download_rounded),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class AccountScreen extends StatefulWidget {
  const AccountScreen({
    super.key,
    required this.api,
    required this.token,
    required this.customer,
    required this.onCustomerChanged,
    required this.onSessionExpired,
  });

  final CustomerApiClient api;
  final String token;
  final Customer customer;
  final ValueChanged<Customer> onCustomerChanged;
  final Future<void> Function() onSessionExpired;

  @override
  State<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends State<AccountScreen> {
  final _anredeController = TextEditingController();
  final _vornameController = TextEditingController();
  final _nachnameController = TextEditingController();
  final _adresseController = TextEditingController();
  final _telefonController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    _applyCustomer(widget.customer);
  }

  @override
  void didUpdateWidget(covariant AccountScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.customer.signature != oldWidget.customer.signature) {
      _applyCustomer(widget.customer, keepPassword: true);
    }
  }

  @override
  void dispose() {
    _anredeController.dispose();
    _vornameController.dispose();
    _nachnameController.dispose();
    _adresseController.dispose();
    _telefonController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _applyCustomer(Customer customer, {bool keepPassword = false}) {
    _anredeController.text = customer.anrede;
    _vornameController.text = customer.vorname;
    _nachnameController.text = customer.nachname;
    _adresseController.text = customer.adresse;
    _telefonController.text = customer.telefon;
    _emailController.text = customer.email;
    if (!keepPassword) {
      _passwordController.clear();
    }
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _isSaving = true;
    });

    try {
      final customer = await widget.api.updateMe(
        widget.token,
        anrede: _anredeController.text.trim(),
        vorname: _vornameController.text.trim(),
        nachname: _nachnameController.text.trim(),
        adresse: _adresseController.text.trim(),
        telefon: _telefonController.text.trim(),
        email: _emailController.text.trim(),
        password: _passwordController.text.trim(),
      );
      if (!mounted) return;
      _passwordController.clear();
      widget.onCustomerChanged(customer);
      _showSnack('Kontodaten wurden aktualisiert.');
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    } finally {
      if (mounted) {
        setState(() {
          _isSaving = false;
        });
      }
    }
  }

  Future<void> _refreshCustomer() async {
    try {
      final customer = await widget.api.getMe(widget.token);
      if (!mounted) return;
      widget.onCustomerChanged(customer);
      _applyCustomer(customer, keepPassword: true);
    } on ApiException catch (error) {
      if (error.statusCode == 401) {
        await widget.onSessionExpired();
        return;
      }
      _showSnack(error.message);
    }
  }

  void _showSnack(String message) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _refreshCustomer,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 120),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Persoenliche Daten',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Pflegen Sie hier Ihre Kontaktdaten fuer das mobile Kundenportal.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: const Color(0xFF6E6258),
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _anredeController,
                    decoration: const InputDecoration(labelText: 'Anrede'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _vornameController,
                    decoration: const InputDecoration(labelText: 'Vorname'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _nachnameController,
                    decoration: const InputDecoration(labelText: 'Nachname'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _adresseController,
                    minLines: 2,
                    maxLines: 4,
                    decoration: const InputDecoration(
                      labelText: 'Adresse',
                      alignLabelWithHint: true,
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _telefonController,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Telefon'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'E-Mail'),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Passwort aendern',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Lassen Sie das Feld leer, wenn das bisherige Passwort unveraendert bleiben soll.',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: const Color(0xFF6E6258),
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _passwordController,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Neues Passwort',
                    ),
                  ),
                  const SizedBox(height: 18),
                  FilledButton.icon(
                    onPressed: _isSaving ? null : _save,
                    icon: _isSaving
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.save_rounded),
                    label: const Text('Kontodaten speichern'),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class CustomerApiClient {
  CustomerApiClient({required this.baseUrl, http.Client? client})
    : _client = client ?? http.Client();

  final String baseUrl;
  final http.Client _client;

  Future<LoginResult> login({
    required String email,
    required String password,
    String? deviceName,
  }) async {
    final json = await _postJson('login', {
      'email': email,
      'password': password,
      'device_name': deviceName ?? '',
    });

    return LoginResult(
      token: json['token']?.toString() ?? '',
      customer: Customer.fromJson(_asMap(json['customer'])),
    );
  }

  Future<void> logout(String token) async {
    await _postJson('logout', const <String, dynamic>{}, token: token);
  }

  Future<Customer> getMe(String token) async {
    final json = await _getJson('me', token: token);
    return Customer.fromJson(_asMap(json['customer']));
  }

  Future<Customer> updateMe(
    String token, {
    required String anrede,
    required String vorname,
    required String nachname,
    required String adresse,
    required String telefon,
    required String email,
    required String password,
  }) async {
    final json = await _postJson('me_update', {
      'anrede': anrede,
      'vorname': vorname,
      'nachname': nachname,
      'adresse': adresse,
      'telefon': telefon,
      'email': email,
      'password': password,
    }, token: token);

    return Customer.fromJson(_asMap(json['customer']));
  }

  Future<List<StatusType>> getStatusTypes(String token) async {
    final json = await _getJson('status_types', token: token);
    final items = _asList(json['types']);
    return items.map(StatusType.fromJson).toList();
  }

  Future<List<StatusEntry>> getStatusEntries(String token) async {
    final json = await _getJson('status_list', token: token);
    final items = _asList(json['items']);
    return items.map(StatusEntry.fromJson).toList();
  }

  Future<void> addStatus(
    String token, {
    required int statusId,
    required String note,
  }) async {
    await _postJson('status_add', {
      'status_id': statusId,
      'note': note,
    }, token: token);
  }

  Future<List<DocumentEntry>> getDocuments(String token) async {
    final json = await _getJson('documents_list', token: token);
    final items = _asList(json['documents']);
    return items.map(DocumentEntry.fromJson).toList();
  }

  Future<List<NetworkPartner>> getNetworkPartners(String token) async {
    final json = await _getJson('network_list', token: token);
    final items = _asList(json['partners']);
    return items.map(NetworkPartner.fromJson).toList();
  }

  Future<File> downloadDocument(String token, DocumentEntry document) async {
    final response = await _client.get(
      _uri('document_download', {'id': document.id.toString()}),
      headers: _authHeaders(token),
    );

    if (response.statusCode < 200 || response.statusCode >= 300) {
      _throwApiError(response);
    }

    final directory = await getTemporaryDirectory();
    final filename = _sanitizeFilename(
      document.filename.isNotEmpty
          ? document.filename
          : 'document-${document.id}.bin',
    );
    final target = File(p.join(directory.path, filename));
    await target.writeAsBytes(response.bodyBytes, flush: true);
    return target;
  }

  Future<void> uploadDocument(
    String token,
    File file, {
    String? label,
    String? note,
  }) async {
    final request = http.MultipartRequest('POST', _uri('document_upload'));
    request.headers.addAll(_authHeaders(token));
    request.fields['label'] = label ?? '';
    request.fields['note'] = note ?? '';
    request.files.add(
      await http.MultipartFile.fromPath(
        'file',
        file.path,
        filename: p.basename(file.path),
      ),
    );

    final streamed = await request.send();
    final response = await http.Response.fromStream(streamed);
    _decodeJsonMap(response);
  }

  Future<void> reportProblem(String token, String note) async {
    await _postJson('problem_report', {'note': note}, token: token);
  }

  Future<Summary> getSummary(String token) async {
    final json = await _getJson('home_summary', token: token);
    return Summary.fromJson(_asMap(json['summary']));
  }

  Future<Map<String, dynamic>> _getJson(String action, {String? token}) async {
    final response = await _client.get(
      _uri(action),
      headers: _jsonHeaders(token),
    );
    return _decodeJsonMap(response);
  }

  Future<Map<String, dynamic>> _postJson(
    String action,
    Map<String, dynamic> payload, {
    String? token,
  }) async {
    final response = await _client.post(
      _uri(action),
      headers: _jsonHeaders(token),
      body: jsonEncode(payload),
    );
    return _decodeJsonMap(response);
  }

  Uri _uri(String action, [Map<String, String>? extraQuery]) {
    final base = Uri.parse(baseUrl);
    final query = <String, String>{
      ...base.queryParameters,
      'action': action,
      ...?extraQuery,
    };
    return base.replace(queryParameters: query);
  }

  Map<String, String> _jsonHeaders(String? token) {
    return <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      ..._authHeaders(token),
    };
  }

  Map<String, String> _authHeaders(String? token) {
    if (token == null || token.isEmpty) {
      return const <String, String>{};
    }
    return <String, String>{'Authorization': 'Bearer $token'};
  }

  Map<String, dynamic> _decodeJsonMap(http.Response response) {
    final body = response.bodyBytes.isEmpty
        ? '{}'
        : utf8.decode(response.bodyBytes, allowMalformed: true);

    dynamic decoded;
    try {
      decoded = jsonDecode(body);
    } catch (_) {
      decoded = null;
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      if (decoded is Map<String, dynamic>) {
        throw ApiException(
          decoded['error']?.toString() ??
              'Anfrage fehlgeschlagen (${response.statusCode})',
          response.statusCode,
        );
      }
      throw ApiException(
        'Anfrage fehlgeschlagen (${response.statusCode})',
        response.statusCode,
      );
    }

    if (decoded is! Map<String, dynamic>) {
      throw ApiException('Unerwartete Serverantwort', response.statusCode);
    }

    if (decoded['success'] == false) {
      throw ApiException(
        decoded['error']?.toString() ?? 'Anfrage fehlgeschlagen',
        response.statusCode,
      );
    }

    return decoded;
  }

  Never _throwApiError(http.Response response) {
    try {
      final decoded = jsonDecode(
        utf8.decode(response.bodyBytes, allowMalformed: true),
      );
      if (decoded is Map<String, dynamic>) {
        throw ApiException(
          decoded['error']?.toString() ??
              'Anfrage fehlgeschlagen (${response.statusCode})',
          response.statusCode,
        );
      }
    } catch (_) {
      // Fallback below.
    }

    throw ApiException(
      'Anfrage fehlgeschlagen (${response.statusCode})',
      response.statusCode,
    );
  }
}

class TokenStore {
  const TokenStore();

  static const _key = 'customer_api_token';

  Future<String?> readToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_key);
  }

  Future<void> writeToken(String token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, token);
  }

  Future<void> clearToken() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key);
  }
}

class LoginResult {
  const LoginResult({required this.token, required this.customer});

  final String token;
  final Customer customer;
}

class Customer {
  const Customer({
    required this.id,
    required this.anrede,
    required this.vorname,
    required this.nachname,
    required this.adresse,
    required this.telefon,
    required this.email,
  });

  final int id;
  final String anrede;
  final String vorname;
  final String nachname;
  final String adresse;
  final String telefon;
  final String email;

  String get displayName {
    final parts = [
      vorname,
      nachname,
    ].map((value) => value.trim()).where((value) => value.isNotEmpty).toList();
    if (parts.isNotEmpty) {
      return parts.join(' ');
    }
    return email.isNotEmpty ? email : 'Kunde';
  }

  String get signature => [
    id.toString(),
    anrede,
    vorname,
    nachname,
    adresse,
    telefon,
    email,
  ].join('|');

  factory Customer.fromJson(Map<String, dynamic> json) {
    return Customer(
      id: _asInt(json['id']),
      anrede: json['anrede']?.toString() ?? '',
      vorname: json['vorname']?.toString() ?? '',
      nachname: json['nachname']?.toString() ?? '',
      adresse: json['adresse']?.toString() ?? '',
      telefon: json['telefon']?.toString() ?? '',
      email: json['email']?.toString() ?? '',
    );
  }
}

class Summary {
  const Summary({
    required this.customerId,
    required this.documentsCount,
    required this.statusCount,
  });

  final int customerId;
  final int documentsCount;
  final int statusCount;

  factory Summary.fromJson(Map<String, dynamic> json) {
    return Summary(
      customerId: _asInt(json['customer_id']),
      documentsCount: _asInt(json['documents_count']),
      statusCount: _asInt(json['status_count']),
    );
  }
}

class StatusType {
  const StatusType({required this.id, required this.label});

  final int id;
  final String label;

  factory StatusType.fromJson(Map<String, dynamic> json) {
    return StatusType(
      id: _asInt(json['id']),
      label: json['bezeichnung']?.toString() ?? '',
    );
  }
}

class StatusEntry {
  const StatusEntry({
    required this.id,
    required this.statusId,
    required this.label,
    required this.note,
    required this.user,
    required this.createdAt,
  });

  final int id;
  final int statusId;
  final String label;
  final String note;
  final String user;
  final DateTime createdAt;

  factory StatusEntry.fromJson(Map<String, dynamic> json) {
    return StatusEntry(
      id: _asInt(json['id']),
      statusId: _asInt(json['status_id']),
      label: json['bezeichnung']?.toString() ?? '',
      note: json['note']?.toString() ?? '',
      user: json['user']?.toString() ?? 'Kunde',
      createdAt: _parseApiDate(json['created_at']),
    );
  }
}

class DocumentEntry {
  const DocumentEntry({
    required this.id,
    required this.label,
    required this.note,
    required this.filename,
    required this.mimeType,
    required this.createdAt,
    required this.createdBy,
    required this.downloadPath,
    required this.downloadUrl,
  });

  final int id;
  final String label;
  final String note;
  final String filename;
  final String mimeType;
  final DateTime createdAt;
  final String createdBy;
  final String downloadPath;
  final String? downloadUrl;

  String get displayLabel => label.trim().isNotEmpty ? label.trim() : filename;

  factory DocumentEntry.fromJson(Map<String, dynamic> json) {
    return DocumentEntry(
      id: _asInt(json['id']),
      label: json['label']?.toString() ?? '',
      note: json['note']?.toString() ?? '',
      filename: json['filename']?.toString() ?? '',
      mimeType: json['mime_type']?.toString() ?? '',
      createdAt: _parseApiDate(json['created_at']),
      createdBy: json['created_by']?.toString() ?? 'System',
      downloadPath: json['download_path']?.toString() ?? '',
      downloadUrl: json['download_url']?.toString(),
    );
  }
}

class NetworkPartner {
  const NetworkPartner({
    required this.id,
    required this.typeLabel,
    required this.nameFirma,
    required this.vorname,
    required this.nachname,
    required this.adresse,
    required this.telefon,
    required this.email,
  });

  final int id;
  final String typeLabel;
  final String nameFirma;
  final String vorname;
  final String nachname;
  final String adresse;
  final String telefon;
  final String email;

  String get personName {
    return [
      vorname,
      nachname,
    ].map((value) => value.trim()).where((value) => value.isNotEmpty).join(' ');
  }

  String get displayName {
    if (nameFirma.trim().isNotEmpty) {
      return nameFirma.trim();
    }
    if (personName.isNotEmpty) {
      return personName;
    }
    return 'Netzwerkpartner';
  }

  factory NetworkPartner.fromJson(Map<String, dynamic> json) {
    return NetworkPartner(
      id: _asInt(json['partner_id'] ?? json['id']),
      typeLabel:
          json['type_label']?.toString() ??
          json['typ_bezeichnung']?.toString() ??
          '',
      nameFirma: json['name_firma']?.toString() ?? '',
      vorname: json['vorname']?.toString() ?? '',
      nachname: json['nachname']?.toString() ?? '',
      adresse: json['adresse']?.toString() ?? '',
      telefon: json['telefon']?.toString() ?? '',
      email: json['email']?.toString() ?? '',
    );
  }
}

class UploadDraft {
  const UploadDraft({required this.label, required this.note});

  final String label;
  final String note;
}

class ApiException implements Exception {
  const ApiException(this.message, [this.statusCode]);

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

Map<String, dynamic> _asMap(dynamic value) {
  if (value is Map<String, dynamic>) {
    return value;
  }
  if (value is Map) {
    return value.map(
      (key, dynamic innerValue) => MapEntry(key.toString(), innerValue),
    );
  }
  return const <String, dynamic>{};
}

List<Map<String, dynamic>> _asList(dynamic value) {
  if (value is List) {
    return value.map(_asMap).toList();
  }
  return const <Map<String, dynamic>>[];
}

int _asInt(dynamic value) {
  if (value is int) {
    return value;
  }
  return int.tryParse(value?.toString() ?? '') ?? 0;
}

DateTime _parseApiDate(dynamic value) {
  final raw = value?.toString().trim() ?? '';
  if (raw.isEmpty) {
    return DateTime.fromMillisecondsSinceEpoch(0);
  }

  final normalized = raw.contains('T') ? raw : raw.replaceFirst(' ', 'T');
  return DateTime.tryParse(normalized) ??
      DateTime.fromMillisecondsSinceEpoch(0);
}

String formatDate(DateTime value) {
  if (value.millisecondsSinceEpoch == 0) {
    return '-';
  }
  return DateFormat('dd.MM.yyyy HH:mm').format(value);
}

String _defaultDocumentLabel(String filename) {
  final base = p.basenameWithoutExtension(filename).trim();
  return base.isEmpty ? 'Upload' : base;
}

String _sanitizeFilename(String filename) {
  final cleaned = filename.replaceAll(RegExp(r'[<>:"/\\|?*]'), '_').trim();
  return cleaned.isEmpty ? 'download.bin' : cleaned;
}

bool _looksLikeImage(String filename) {
  final lower = filename.toLowerCase();
  return lower.endsWith('.jpg') ||
      lower.endsWith('.jpeg') ||
      lower.endsWith('.png') ||
      lower.endsWith('.gif') ||
      lower.endsWith('.webp') ||
      lower.endsWith('.bmp') ||
      lower.endsWith('.heic');
}

String _mimeFromFilename(String filename) {
  final lower = filename.toLowerCase();
  if (lower.endsWith('.pdf')) {
    return 'application/pdf';
  }
  if (lower.endsWith('.doc') || lower.endsWith('.docx')) {
    return 'application/msword';
  }
  if (lower.endsWith('.xls') ||
      lower.endsWith('.xlsx') ||
      lower.endsWith('.csv')) {
    return 'application/vnd.ms-excel';
  }
  if (_looksLikeImage(lower)) {
    return 'image/*';
  }
  if (lower.endsWith('.zip')) {
    return 'application/zip';
  }
  return 'application/octet-stream';
}

IconData _iconForMime(String mimeType) {
  final mime = mimeType.toLowerCase();
  if (mime.contains('pdf')) {
    return Icons.picture_as_pdf_rounded;
  }
  if (mime.contains('image')) {
    return Icons.image_rounded;
  }
  if (mime.contains('word')) {
    return Icons.description_rounded;
  }
  if (mime.contains('sheet') ||
      mime.contains('excel') ||
      mime.contains('csv')) {
    return Icons.table_chart_rounded;
  }
  if (mime.contains('zip')) {
    return Icons.folder_zip_rounded;
  }
  return Icons.insert_drive_file_rounded;
}
