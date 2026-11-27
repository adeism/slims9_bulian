#!/usr/bin/env python3
"""Opt-in HTTP smoke test of a LOCAL disposable M001 fixture, writes OFF.

Set SLIMS_TEST_HTTP_ALLOW=1 and run against a member-enabled server connected
only to disposable data, with test database writes/reservations disabled.
This creates baskets and revokes all M001 sessions in that server's store.
Never use real member data. Localhost alone is not proof of disposability.
"""
import argparse
import csv
from http.cookiejar import CookieJar
import io
import os
import re
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, build_opener, HTTPCookieProcessor


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', default='http://127.0.0.1:8080')
    args = parser.parse_args()
    base = args.url.rstrip('/')
    url = urlsplit(base)
    if (os.environ.get('SLIMS_TEST_HTTP_ALLOW') != '1' or url.scheme != 'http'
            or url.hostname != '127.0.0.1' or url.username or url.password
            or url.path or url.query or url.fragment):
        raise SystemExit('Requires SLIMS_TEST_HTTP_ALLOW=1 and http://127.0.0.1:port for a disposable fixture')

    def client():
        return build_opener(HTTPCookieProcessor(CookieJar()))

    def request(client, path, form=None):
        data = None if form is None else urlencode(form).encode()
        try:
            response = client.open(base + path, data, timeout=20)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode('utf-8-sig'), response.url

    def token(text):
        return re.search(r'name="csrf_token" value="([a-f0-9]+)"', text).group(1)

    def signin(client, target='/member'):
        status, _, html, _ = request(client, target)
        assert status == 200
        form = {'action': 'login', 'csrf_token': token(html), 'memberID': 'M001', 'memberPassWord': 'siti rahma'}
        continuation = re.search(r'name="return_title" value="([0-9]+)"', html)
        if continuation:
            form['return_title'] = continuation.group(1)
        status, headers, html, destination = request(client, '/member', form)
        assert status == 200, status
        assert 'Member title actions' in html or 'Hi, Siti Rahma' in html
        assert headers.get_all('Referrer-Policy') == ['no-referrer']
        assert headers.get_all('X-Content-Type-Options') == ['nosniff']
        return html, destination

    first, second = client(), client()
    target = '/member?sec=title_actions&biblio_id=2'
    html, destination = signin(first, target)
    assert destination.endswith(target)
    assert 'Add to title basket' in html
    assert 'Reserve in test database' not in html
    assert 'Add bookmark (test only)' not in html
    signin(second)
    status, _, html, _ = request(first, '/member', {
        'action': 'basket_add', 'csrf_token': token(html), 'biblio_id': '2'})
    assert status == 200 and '1 of 20 titles' in html
    assert 'Linux In a Nutshell' in html
    status, headers, data, _ = request(first, '/member?sec=current_loan&format=csv')
    assert status == 200 and headers['Content-Type'].startswith('text/csv')
    assert 'attachment;' in headers['Content-Disposition']
    assert 'no-store' in headers['Cache-Control']
    rows = list(csv.reader(io.StringIO(data)))
    assert len(rows) == 2 and rows[0][-1] == 'Due Date'
    status, headers, report, _ = request(first, '/index.php?p=download_member_loan_report')
    assert status == 200 and 'Current loans (1)' in report and 'Returned loans (2)' in report
    assert 'no-store' in headers['Cache-Control'] and '<style>' not in report
    status, headers, combined, _ = request(first, '/member?sec=loan_report&format=csv')
    assert status == 200 and headers['Content-Type'].startswith('text/csv')
    report_rows = list(csv.reader(io.StringIO(combined)))
    assert len(report_rows) == 4 and report_rows[0][-1] == 'Return Date'
    with first.open(Request(
            base + '/member?sec=loan_report', method='HEAD'), timeout=20) as head:
        assert head.status == 200 and head.read() == b''
    assert request(first, '/member?sec=loan_report&memberID=M002')[0] == 400
    status, _, html, _ = request(first, '/member?sec=reservation_list')
    assert status == 200 and 'My Stored Reservations' in html and 'Read-only' in html
    status, _, html, _ = request(first, '/member?sec=my_account')
    assert status == 200 and 'siti.rahma@example.org' in html
    assert 'budi.santoso@example.org' not in html
    assert 'Change password (test only)' not in html
    csrf = token(html)
    # With write switches off, authenticated attempts are unavailable.
    for action in ['bookmark_add', 'test_reserve', 'change_password']:
        assert request(first, '/member', {'action': action, 'csrf_token': csrf, 'biblio_id': '2'})[0] == 404
    status, _, html, _ = request(first, '/member', {'action': 'logout_all', 'csrf_token': csrf})
    assert status == 200 and 'Library Member Login' in html
    status, headers, html, _ = request(second, '/member?format=csv')
    assert status == 200 and 'Library Member Login' in html
    assert headers.get('Content-Disposition') is None
    print('PASS: HTTP login continuation, basket, CSV, combined report/HEAD, stored reservations, write refusal, logout-all and wire headers')


if __name__ == '__main__':
    main()
