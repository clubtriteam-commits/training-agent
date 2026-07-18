@{
    # SSH потребител и хост на сървъра
    User       = 'trailser'
    Host       = 'example.com'          # <- смени с реалния хост
    Port       = 22                     # <- смени, ако SSH портът е друг

    # Директорията, от която се сервира дашбордът (напр. public_html/dashboard)
    RemotePath = '/home/trailser/public_html/dashboard'
}
