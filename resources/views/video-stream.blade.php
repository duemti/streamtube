<HTML>
	<BODY>
		<h2>Video Stream</h2>

		<form>
			<label for="msg">Send Message:</label></br>
			<input id="msg" type="text" name="msg"></br>
			<input id="msgb" type="button" value="Send">
		</form>

		<div id="content"></div>

		<script>
			function myprint(output) { // replace your calls to _print_ with _myprint_
				var p = document.createElement("p"); // Create a <p> element
				var t = document.createTextNode(output); // Create a text node
				p.appendChild(t);
				document.getElementById("content").appendChild(p);
			}

			var connection = new WebSocket("{{ $socketUrl }}");
			var msg = document.getElementById('msg');
			var msgb = document.getElementById('msgb');
			
			connection.onopen = function(e) {
				myprint("Connection open successfully!");
				console.log("Connection open successfully!");
			};

			connection.onmessage = function(e) {
				myprint(e.data);
				console.log(e.data);
			};

			msgb.onclick = function(e) {
				connection.send(msg.value);
			};

		</script>
	</BODY>
</HTML>
